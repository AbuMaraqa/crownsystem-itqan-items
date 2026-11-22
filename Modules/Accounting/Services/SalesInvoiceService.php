<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Enums\SalesReturnStatus;
use Modules\Accounting\Entities\SalesOrderItem;
use Modules\Accounting\Support\ItemsGrid;
use Modules\Accounting\Support\SalesServiceLine;
use Modules\Accounting\Support\GridLine;
use Modules\Accounting\Support\WeightPieces;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Items\Entities\Product;

class SalesInvoiceService
{
    private const SOURCE_MODULE = 'Accounting';

    private const RELATIONS = ['salesOrder', 'client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces', 'items.serviceProvider'];

    public function create(array $data): SalesInvoice
    {
        return DB::transaction(function () use ($data) {
            $order = $this->salesOrder($data['sales_order_id'] ?? null, true);
            $itemsInput = $data['items'] ?? [];

            if ($order) {
                $this->assertSalesOrderCanBeInvoiced($order);

                if ($itemsInput === []) {
                    $itemsInput = $this->orderItemsForInvoice($order);
                }
            }

            $items = $this->normalizeItems($itemsInput);
            $data = $this->normalizeData($data, $order);
            $data = array_merge($data, $this->calculateTotals($items, $data['invoice_discount'], $data['tax_rate']));
            $data['reference_no'] = app(ReferenceNumberService::class)->generate(
                'sales_invoice',
                $data['invoice_date'],
                ['prefix' => 'SINV']
            );
            $data['status'] = SalesInvoiceStatus::DRAFT->value();
            $data['created_by'] = $data['created_by'] ?? auth()->id();

            $invoice = SalesInvoice::create($data);
            $this->syncItems($invoice, $items);

            if ($order) {
                $order->update([
                    'status' => SalesOrderStatus::INVOICED->value(),
                    'invoiced_at' => now(),
                ]);
            }

            return $invoice->refresh()->load(self::RELATIONS);
        });
    }

    public function createFromOrder(SalesOrder $order, array $data = []): SalesInvoice
    {
        return DB::transaction(function () use ($order, $data) {
            $order = SalesOrder::query()
                ->with(['tax', 'items', 'salesInvoices'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->assertSalesOrderCanBeInvoiced($order);

            return $this->create(array_replace([
                'sales_order_id' => $order->id,
                'client_id' => $order->client_id,
                'branch_id' => $order->branch_id,
                'warehouse_id' => $order->warehouse_id,
                'invoice_date' => now()->toDateString(),
                'currency_id' => $order->currency_id,
                'tax_id' => $order->tax_id,
                'invoice_discount' => $order->order_discount,
                'notes' => $order->notes,
                'items' => $this->orderItemsForInvoice($order),
            ], $data));
        });
    }

    /**
     * حفظ نموذج الفاتورة من صفحتها (انشاء او تعديل). الفاتورة اليدوية (بلا طلبية): الصنف الرئيسي
     * الجديد لا يُحفظ بلا اصنافه الفرعية. فاتورة الطلبية تأخذ اصناف الطلبية كما هي. بنود قسم
     * الخدمات تُضم الى البنود بعد التحقق انها خدمات؛ وفاتورة جديدة من طلبية بلا بنود تأخذ كل
     * بنود الطلبية (create).
     *
     * @param  array<string, mixed>  $data  الرأس و items و services
     */
    public function saveForm(?SalesInvoice $invoice, array $data): SalesInvoice
    {
        $items = array_values($data['items'] ?? []);
        $fromOrder = filled($invoice ? $invoice->sales_order_id : ($data['sales_order_id'] ?? null));

        if (! $fromOrder) {
            ItemsGrid::ensureMainItemsHaveSubItems($items);
        }

        $data['items'] = SalesServiceLine::merge($items, $data['services'] ?? []);
        unset($data['services']);

        return $invoice ? $this->update($invoice, $data) : $this->create($data);
    }

    /**
     * يحفظ تعديلات النموذج (ما دامت الفاتورة مسودة وأُرسلت) ثم ينفذ الاجراء عليها (الترحيل)، في
     * معاملة واحدة.
     *
     * @template T
     *
     * @param  callable(SalesInvoice): T  $action
     * @return T
     */
    public function saveThen(SalesInvoice $invoice, ?array $data, callable $action): mixed
    {
        return DB::transaction(function () use ($invoice, $data, $action) {
            if ($data !== null && $invoice->canBeEdited()) {
                $invoice = $this->saveForm($invoice, $data);
            }

            return $action($invoice);
        });
    }

    /**
     * الطلبيات التي تُفوتر لهذا الزبون: غير ملغاة وبلا فاتورة قائمة.
     *
     * @return list<array{value: int, label: string}>
     */
    public function orderOptions(mixed $clientId = null, ?string $search = null): array
    {
        return SalesOrder::query()
            ->where('status', '!=', SalesOrderStatus::CANCELLED->value())
            ->whereDoesntHave('activeSalesInvoices')
            ->when(filled($clientId), fn ($query) => $query->where('client_id', $clientId))
            ->when(filled($search), fn ($query) => $query->where('reference_no', 'like', "%{$search}%"))
            ->latest('order_date')
            ->limit(50)
            ->get(['id', 'reference_no'])
            ->map(fn (SalesOrder $order): array => ['value' => (int) $order->id, 'label' => (string) $order->reference_no])
            ->all();
    }

    /**
     * ما يأخذه نموذج الفاتورة من الطلبية المختارة: الطرف والمخزن والعملة (بسعر صرف تاريخ
     * الفاتورة) والضريبة والخصم، ومجاميع الطلبية للعرض. البنود تنتقل عند الحفظ (create).
     *
     * @return array<string, mixed>
     */
    public function orderDefaults(SalesOrder $order, ?string $invoiceDate = null): array
    {
        $rate = app(CurrencyExchangeRateService::class)->rateFor($order->currency_id, $invoiceDate ?: now()->toDateString());

        return [
            'sales_order_id' => $order->id,
            'client_id' => $order->client_id,
            'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id,
            'currency_id' => $order->currency_id,
            'tax_id' => $order->tax_id,
            'exchange_rate' => $rate ? number_format($rate, 8, '.', '') : null,
            'invoice_discount' => number_format((float) $order->order_discount, 4, '.', ''),
            'totals' => [
                'sub_total' => (float) $order->sub_total,
                'items_discount' => (float) $order->items_discount,
                'document_discount' => (float) $order->order_discount,
                'tax_amount' => (float) $order->tax_amount,
                'total_amount' => (float) $order->total_amount,
            ],
        ];
    }

    public function update(SalesInvoice $invoice, array $data): SalesInvoice
    {
        if (! $invoice->canBeEdited()) {
            throw new DomainException(__('Only draft sales invoices can be updated.'));
        }

        return DB::transaction(function () use ($invoice, $data) {
            $order = $this->salesOrder($data['sales_order_id'] ?? $invoice->sales_order_id);
            $items = $this->normalizeItems($data['items'] ?? []);
            $data = $this->normalizeData($data, $order, false);
            unset($data['reference_no'], $data['created_by'], $data['journal_entry_id']);

            $data = array_merge($data, $this->calculateTotals($items, $data['invoice_discount'], $data['tax_rate']));

            $invoice->update($data);
            $this->syncItems($invoice, $items);

            return $invoice->refresh()->load(self::RELATIONS);
        });
    }

    public function delete(SalesInvoice $invoice): void
    {
        if (! $invoice->canBeEdited()) {
            throw new DomainException(__('Only draft sales invoices can be deleted.'));
        }

        DB::transaction(function () use ($invoice) {
            $invoice->load(['journalEntry.lines', 'salesOrder.salesInvoices']);
            $salesOrder = $invoice->salesOrder;

            if ($invoice->journalEntry) {
                $invoice->journalEntry->lines()->delete();
                $invoice->journalEntry->delete();
            }

            $invoice->items()->delete();
            $invoice->delete();

            if ($salesOrder) {
                $this->restoreOrderStatus($salesOrder);
            }
        });
    }

    public function post(SalesInvoice $invoice): SalesInvoice
    {
        if (! $invoice->canBePosted()) {
            throw new DomainException(__('Only draft sales invoices can be posted.'));
        }

        return DB::transaction(function () use ($invoice) {
            $invoice = SalesInvoice::query()
                ->with(['items', 'journalEntry'])
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            // يُعاد الفحص بعد القفل: طلبان متزامنان يتجاوزان الفحص الاول معاً فتُرحَّل الفاتورة مرتين.
            if (! $invoice->canBePosted()) {
                throw new DomainException(__('Only draft sales invoices can be posted.'));
            }

            if ($invoice->items->isEmpty() || (float) $invoice->total_amount <= 0) {
                throw new DomainException(__('Sales invoice must have at least one item before posting.'));
            }

            if ($invoice->journalEntry) {
                app(JournalEntryService::class)->updateDraft(
                    $invoice->journalEntry,
                    $this->journalData($invoice),
                    $this->journalLines($invoice)
                );

                $entry = $invoice->journalEntry->refresh();
            } else {
                $entry = $this->createJournalEntry($invoice);
                $invoice->update(['journal_entry_id' => $entry->id]);
            }

            app(JournalEntryService::class)->post($entry);
            app(InvoiceStockService::class)->issueSalesInvoice($invoice);

            $invoice->update(['status' => SalesInvoiceStatus::POSTED->value()]);
            // المتبقي يُضبط عند الترحيل فتظهر الفاتورة في لوحة تخصيص سندات القبض.
            app(PaymentAllocationService::class)->refreshInvoice($invoice);

            return $invoice->refresh()->load([...self::RELATIONS, 'journalEntry']);
        });
    }

    /**
     * الغاء فاتورة مرحّلة (كما في ERPNext): قيد عكسي بدل الحذف، وتعود بضاعتها الى المخزن
     * ويُعكس قيد تكلفتها، وتبقى الفاتورة للاطلاع. تُمنع ان كان لها مرتجع مرحّل او دفعات
     * مخصَّصة؛ وتعود الطلبية قابلة للفوترة.
     */
    public function cancel(SalesInvoice $invoice, ?string $reason = null): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $invoice = SalesInvoice::query()
                ->with(['journalEntry', 'salesOrder'])
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            if (! $invoice->isPosted()) {
                throw new DomainException(__('Only posted sales invoices can be cancelled.'));
            }

            $hasReturns = SalesReturn::query()
                ->where('sales_invoice_id', $invoice->id)
                ->where('status', SalesReturnStatus::POSTED->value())
                ->exists();

            if ($hasReturns) {
                throw new DomainException(__('This sales invoice has posted sales returns. Cancel them first.'));
            }

            $allocations = app(PaymentAllocationService::class);
            $allocations->assertInvoiceHasNoAllocations($invoice);

            $date = now()->toDateString();

            if ($invoice->journalEntry && $invoice->journalEntry->status === JournalEntryStatus::POSTED) {
                app(JournalEntryService::class)->void($invoice->journalEntry, __('Cancellation of').' '.$invoice->reference_no);
            }

            // سند التسليم التلقائي يُلغى، وبضاعة الفاتورة بلا طلبية تعود الى المخزن مع عكس قيد تكلفتها.
            app(InvoiceStockService::class)->cancelSalesInvoice($invoice, $date);

            $invoice->update([
                'status' => SalesInvoiceStatus::CANCELLED->value(),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => filled($reason) ? $reason : null,
            ]);

            $allocations->refreshInvoice($invoice);

            if ($invoice->salesOrder) {
                $this->restoreOrderStatus($invoice->salesOrder->refresh());
            }

            return $invoice->refresh()->load([...self::RELATIONS, 'journalEntry']);
        });
    }

    /**
     * الطلبية المفوترة تعود «قيد التجهيز» حين لا تبقى لها فاتورة غير ملغاة، فتُفوتر من جديد.
     */
    private function restoreOrderStatus(SalesOrder $order): void
    {
        if ($order->status !== SalesOrderStatus::INVOICED || $order->activeSalesInvoices()->exists()) {
            return;
        }

        $order->update([
            'status' => SalesOrderStatus::PREPARING->value(),
            'invoiced_at' => null,
        ]);
    }

    /**
     * قيم المستند المصدر (الضريبة والخصم والملاحظات) تُورَّث عند الانشاء فقط؛ عند التعديل
     * يُحفظ ما ادخله المستخدم ولو افرغ الحقل.
     */
    private function normalizeData(array $data, ?SalesOrder $order = null, bool $inheritFromSource = true): array
    {
        $invoiceDate = $data['invoice_date'] ?? now()->toDateString();
        $currencyId = (int) ($data['currency_id'] ?? $order?->currency_id ?? 0);
        $tax = ! empty($data['tax_id'] ?? null) ? Tax::query()->find($data['tax_id']) : ($inheritFromSource ? $order?->tax : null);
        $exchangeRate = app(CurrencyExchangeRateService::class)->rateFor($currencyId, $invoiceDate);

        if (! $exchangeRate) {
            throw new DomainException(__('Exchange rate is missing for selected currency and invoice date.'));
        }

        return [
            'sales_order_id' => filled($data['sales_order_id'] ?? null) ? (int) $data['sales_order_id'] : $order?->id,
            'client_id' => (int) ($data['client_id'] ?? $order?->client_id),
            'branch_id' => filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : $order?->branch_id,
            'warehouse_id' => filled($data['warehouse_id'] ?? null) ? (int) $data['warehouse_id'] : $order?->warehouse_id,
            'invoice_date' => $invoiceDate,
            'due_date' => $data['due_date'] ?? null,
            'currency_id' => $currencyId,
            'tax_id' => $tax?->id,
            'exchange_rate' => $this->decimalValue($exchangeRate, 1, 8),
            'invoice_discount' => $this->decimalValue($data['invoice_discount'] ?? ($inheritFromSource ? $order?->order_discount : null) ?? 0),
            'tax_rate' => $this->decimalValue($tax?->rate ?? 0),
            'notes' => $data['notes'] ?? ($inheritFromSource ? $order?->notes : null),
        ];
    }

    private function normalizeItems(array $items): array
    {
        return collect($items)
            ->filter(fn (array $item) => GridLine::hasOwner($item))
            ->map(function (array $item): array {
                // سطر صنف، او سطر مجموعة سعر بلا صنف (التصنيف × اللون) قطعه ألوان مقاطعه.
                $product = GridLine::product($item);
                $quantity = $this->decimalValue($item['quantity'] ?? 1, 1);
                // صنف الوزن باصنافه الفرعية: الكمية المفوترة (كغم) = الوزن الفعلي، وصفر بدونه.
                $weight = WeightPieces::line($item, $product, ['sales_order_item_piece_id']);
                $quantity = $weight['pricing_weight'] ?? $quantity;
                $unitPrice = $this->decimalValue($item['unit_price'] ?? $product?->base_price ?? 0);
                $discountMode = ($item['discount_mode'] ?? 'rate') === 'amount' ? 'amount' : 'rate';
                $discountRate = $this->decimalValue($item['discount_rate'] ?? 0);
                $discountAmount = $this->decimalValue($item['discount_amount'] ?? 0);

                // سطر وزن له قطع بوزن وبلا وزن فعلي جائز بمبلغ صفر.
                if ($quantity <= 0 && $weight['total_weight'] <= 0) {
                    throw new DomainException(__('Sales invoice item quantity must be greater than zero.'));
                }

                if ($unitPrice < 0) {
                    throw new DomainException(__('Sales invoice item price cannot be negative.'));
                }

                if ($discountRate < 0 || $discountRate > 100) {
                    throw new DomainException(__('Sales invoice item discount must be between 0 and 100.'));
                }

                $gross = round($quantity * $unitPrice, 4);

                if ($discountMode === 'amount') {
                    if ($discountAmount < 0) {
                        throw new DomainException(__('Sales invoice item discount amount cannot be negative.'));
                    }

                    if ($discountAmount > $gross) {
                        throw new DomainException(__('Sales invoice item discount amount cannot exceed item total.'));
                    }

                    $discountRate = $gross > 0 ? round(($discountAmount / $gross) * 100, 4) : 0;
                } else {
                    $discountAmount = round($gross * ($discountRate / 100), 4);
                }

                return array_merge([
                    'id' => $item['id'] ?? null,
                    'sales_order_item_id' => filled($item['sales_order_item_id'] ?? null) ? (int) $item['sales_order_item_id'] : null,
                    'product_id' => $product?->id,
                    'category_finish_id' => GridLine::groupId($item),
                    'barcode' => $item['barcode'] ?? $product?->barcode,
                    'description' => GridLine::description($item, $product),
                    'quantity' => $quantity,
                    'total_weight' => $weight['total_weight'],
                    'actual_weight' => $weight['actual_weight'],
                    'unit_price' => $unitPrice,
                    'discount_rate' => $discountRate,
                    'discount_amount' => $discountAmount,
                    'line_total' => max(round($gross - $discountAmount, 4), 0),
                    'notes' => $item['notes'] ?? null,
                    'created_by' => $item['created_by'] ?? auth()->id(),
                    'pieces' => $weight['pieces'],
                ], SalesServiceLine::fields($item, $product, $quantity));
            })
            ->values()
            ->toArray();
    }

    private function orderItemsForInvoice(SalesOrder $order): array
    {
        return $order->items
            ->map(fn ($item): array => [
                'sales_order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'category_finish_id' => $item->category_finish_id,
                'barcode' => $item->barcode,
                'description' => $item->description,
                'quantity' => $item->invoiceQuantity(),
                'actual_weight' => $item->actual_weight,
                'unit_price' => $item->unit_price,
                'discount_rate' => $item->discount_rate,
                'discount_amount' => $item->discount_amount,
                'pricing_basis' => $item->pricing_basis,
                'unit_cost' => $item->unit_cost,
                'cost_source' => $item->cost_source,
                'service_provider_id' => $item->service_provider_id,
                'notes' => $item->notes,
                'pieces' => self::orderPieces($item),
            ])
            ->values()
            ->toArray();
    }

    /**
     * اصناف سطر الطلبية الفرعية كما تُنقل الى سطر الفاتورة، مربوطة بقطعها في الطلبية.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function orderPieces(SalesOrderItem $item): array
    {
        return $item->pieces
            ->map(fn ($piece): array => [
                'sales_order_item_piece_id' => $piece->id,
                'product_id' => $piece->product_id,
                'barcode' => $piece->barcode,
                'description' => $piece->description,
                'piece_count' => $piece->piece_count,
                'piece_length' => $piece->piece_length,
                'weight_per_meter' => $piece->weight_per_meter,
            ])
            ->values()
            ->all();
    }

    private function calculateTotals(array $items, float $invoiceDiscount, float $taxRate): array
    {
        $subTotal = collect($items)->sum(fn (array $item) => round($item['quantity'] * $item['unit_price'], 4));
        $itemsDiscount = collect($items)->sum('discount_amount');
        $netBeforeInvoiceDiscount = max(round($subTotal - $itemsDiscount, 4), 0);
        $invoiceDiscount = min(max($invoiceDiscount, 0), $netBeforeInvoiceDiscount);
        $taxableTotal = max(round($netBeforeInvoiceDiscount - $invoiceDiscount, 4), 0);
        $taxAmount = round($taxableTotal * ($taxRate / 100), 4);

        return [
            'sub_total' => round($subTotal, 4),
            'items_discount' => round($itemsDiscount, 4),
            'invoice_discount' => round($invoiceDiscount, 4),
            'tax_amount' => $taxAmount,
            'total_amount' => round($taxableTotal + $taxAmount, 4),
        ];
    }

    private function syncItems(SalesInvoice $invoice, array $items): void
    {
        $incomingIds = collect($items)->pluck('id')->filter()->values();

        $invoice->items()
            ->when($incomingIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $incomingIds))
            ->when($incomingIds->isEmpty(), fn ($query) => $query)
            ->delete();

        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $pieces = $item['pieces'] ?? [];
            unset($item['id'], $item['pieces']);

            if ($id && $existingItem = $invoice->items()->whereKey($id)->first()) {
                $existingItem->update($item);
            } else {
                $existingItem = $invoice->items()->create($item);
            }

            WeightPieces::sync($existingItem->pieces(), $pieces);
        }
    }

    private function createJournalEntry(SalesInvoice $invoice): JournalEntry
    {
        return app(JournalEntryService::class)->createDraft(
            $this->journalData($invoice),
            $this->journalLines($invoice)
        );
    }

    private function journalData(SalesInvoice $invoice): array
    {
        return [
            'entry_date' => $invoice->invoice_date?->toDateString(),
            'reference_no' => $invoice->reference_no,
            'source_module' => self::SOURCE_MODULE,
            'source_type' => 'SalesInvoice',
            'sourceable_id' => $invoice->id,
            'sourceable_type' => $invoice::class,
            'description' => __('Sales invoice').' '.$invoice->reference_no,
            'created_by' => $invoice->created_by ?? auth()->id(),
        ];
    }

    /**
     * قيد الفاتورة:
     *   من حـ/ العميل (حسابه الفرعي)                 = الاجمالي
     *       الى حـ/ ايراد كل نوع (سلع، خدمات، نقل)    = صافي بنوده بعد خصم الفاتورة
     *       الى حـ/ الضريبة                          = الضريبة
     * ولكل خدمة لها تكلفة (مقابلة الايراد بالتكلفة):
     *   من حـ/ تكلفة الخدمة
     *       الى حـ/ تكاليف محمّلة، او حساب الجهة المنفذة، او مصروفات مستحقة
     */
    private function journalLines(SalesInvoice $invoice): array
    {
        $ledger = app(SalesLedgerAccounts::class);
        $invoice->loadMissing('items.product');
        $reference = $invoice->reference_no;

        $lines = [
            $this->journalLine($invoice, $ledger->customerAccountId($invoice->client_id), (float) $invoice->total_amount, 0, __('Sales invoice receivable').' '.$reference),
        ];

        $revenueLines = $invoice->items->map(fn ($item): array => [
            'account_id' => $ledger->revenueAccountId($item->product, $item->isService()),
            'line_total' => (float) $item->line_total,
        ]);
        $netRevenue = round((float) $invoice->total_amount - (float) $invoice->tax_amount, 4);

        foreach ($ledger->allocateRevenue($revenueLines, $netRevenue) as $accountId => $amount) {
            $lines[] = $this->journalLine($invoice, $accountId, 0, $amount, __('Sales invoice revenue').' '.$reference);
        }

        if ((float) $invoice->tax_amount > 0) {
            $lines[] = $this->journalLine($invoice, $ledger->mapped(SalesLedgerAccounts::TAX_KEY), 0, (float) $invoice->tax_amount, __('Sales invoice tax').' '.$reference);
        }

        foreach ($invoice->items as $item) {
            if (! $item->isService() || (float) $item->cost_total <= 0) {
                continue;
            }

            $description = __('Cost of service').' '.$item->description.' - '.$reference;

            $lines[] = $this->journalLine($invoice, $ledger->serviceCostAccountId($item->product), (float) $item->cost_total, 0, $description);
            $lines[] = $this->journalLine($invoice, $ledger->serviceCostCreditAccountId($item->cost_source, $item->service_provider_id), 0, (float) $item->cost_total, $description);
        }

        return $lines;
    }

    private function journalLine(SalesInvoice $invoice, int $accountId, float $debit, float $credit, string $description): array
    {
        return [
            'account_id' => $accountId,
            'branch_id' => $invoice->branch_id,
            'currency_id' => $invoice->currency_id,
            'exchange_rate' => $invoice->exchange_rate,
            'debit' => round($debit, 4),
            'credit' => round($credit, 4),
            'description' => $description,
            'created_by' => $invoice->created_by ?? auth()->id(),
        ];
    }

    private function salesOrder(mixed $orderId, bool $lockForUpdate = false): ?SalesOrder
    {
        if (blank($orderId)) {
            return null;
        }

        $query = SalesOrder::query()
            ->with(['tax', 'items', 'salesInvoices'])
            ->whereKey($orderId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function assertSalesOrderCanBeInvoiced(SalesOrder $order): void
    {
        if ($order->status === SalesOrderStatus::CANCELLED) {
            throw new DomainException(__('Cancelled sales orders cannot be invoiced.'));
        }

        if ($order->salesInvoices->contains(fn (SalesInvoice $invoice) => $invoice->status !== SalesInvoiceStatus::CANCELLED)) {
            throw new DomainException(__('Sales order already has a sales invoice.'));
        }

        if ($order->items->isEmpty() || (float) $order->total_amount <= 0) {
            throw new DomainException(__('Sales order must have at least one item.'));
        }
    }

    private function decimalValue(mixed $value, float $default = 0, int $scale = 4): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        if (! is_numeric($value)) {
            throw new DomainException(__('Invalid numeric value.'));
        }

        return round((float) $value, $scale);
    }
}
