<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Accounting\Entities\PurchaseReturn;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\PurchaseReturnStatus;
use Modules\Accounting\Support\ItemsGrid;
use Modules\Accounting\Support\GridLine;
use Modules\Accounting\Support\WeightPieces;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Items\Entities\Product;
use Modules\Items\Support\ProductSearch;

class PurchaseInvoiceService
{
    private const SOURCE_MODULE = 'Accounting';

    public function create(array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($data) {
            $order = $this->purchaseOrder($data['purchase_order_id'] ?? null, true);
            $itemsInput = $data['items'] ?? [];
            $quotation = null;

            if ($order) {
                $this->assertPurchaseOrderCanBeInvoiced($order);
                // عرض السعر المعتمد للطلبية هو مصدر اسعار فاتورتها، فترتبط به دائماً.
                $quotation = $this->invoiceableQuotation($order);

                if ($itemsInput === []) {
                    $itemsInput = $this->orderItemsForInvoice($order, $quotation);
                }
            }

            $items = $this->normalizeItems($itemsInput);
            $data = $this->normalizeData($data, $order, true, $quotation);
            $data['purchase_quotation_id'] = $quotation?->id;
            $data = array_merge($data, $this->calculateTotals($items, $data['invoice_discount'], $data['tax_rate']));
            $data['reference_no'] = app(ReferenceNumberService::class)->generate(
                'purchase_invoice',
                $data['invoice_date'],
                ['prefix' => 'PINV']
            );
            $data['status'] = PurchaseInvoiceStatus::DRAFT->value();
            $data['created_by'] = $data['created_by'] ?? auth()->id();

            $invoice = PurchaseInvoice::create($data);
            $this->syncItems($invoice, $items);

            if ($order) {
                $order->update([
                    'status' => PurchaseOrderStatus::INVOICED->value(),
                    'invoiced_at' => now(),
                ]);
            }

            if ($quotation) {
                app(PurchaseQuotationService::class)->markInvoiced($quotation);
            }

            return $invoice->refresh()->load(['purchaseOrder', 'purchaseQuotation', 'client', 'branch', 'warehouse', 'currency', 'tax', 'items.product']);
        });
    }

    public function createFromOrder(PurchaseOrder $order, array $data = []): PurchaseInvoice
    {
        return DB::transaction(function () use ($order, $data) {
            $order = PurchaseOrder::query()
                ->with(['tax', 'items.product', 'purchaseInvoices'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->assertPurchaseOrderCanBeInvoiced($order);

            return $this->create(array_replace(
                ['invoice_date' => now()->toDateString()],
                $this->draftFromOrder($order),
                $data
            ));
        });
    }

    /**
     * فاتورة الشراء من عرض السعر المعتمد: بطلبيته واسعاره.
     */
    public function createFromQuotation(PurchaseQuotation $quotation, array $data = []): PurchaseInvoice
    {
        if (! $quotation->canCreateInvoice()) {
            throw new DomainException(__('Only approved purchase quotations can be invoiced.'));
        }

        return $this->createFromOrder($quotation->purchaseOrder()->firstOrFail(), $data);
    }

    /**
     * ما تُعبأ به فاتورة شراء من طلبية: اسعار وعملة وضريبة وخصم عرض السعر المعتمد ان وُجد،
     * والا سطور الطلبية بلا اسعار (تُدخل في الفاتورة) بالعملة الاساسية.
     *
     * @return array<string, mixed>
     */
    public function draftFromOrder(PurchaseOrder $order): array
    {
        $order->loadMissing(['tax', 'items.product']);
        $quotation = $this->invoiceableQuotation($order);

        return [
            'purchase_order_id' => $order->id,
            'client_id' => $order->client_id,
            'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id,
            'currency_id' => $quotation?->currency_id ?? $order->currency_id ?? app(CurrencyExchangeRateService::class)->baseCurrencyId(),
            'tax_id' => $quotation ? $quotation->tax_id : $order->tax_id,
            'invoice_discount' => (float) ($quotation ? $quotation->quotation_discount : $order->order_discount),
            'notes' => $order->notes,
            'items' => $this->orderItemsForInvoice($order, $quotation),
        ];
    }

    /**
     * حفظ شاشة الادخال (لوحة Vue). الفاتورة اليدوية (بلا طلبية): صنف الوزن الجديد لا
     * يُحفظ بلا اصناف فرعية لها وزن، كما في الطلبيات؛ فاتورة الطلبية اصنافها اصناف الطلبية.
     */
    public function saveFromEntry(?PurchaseInvoice $invoice, array $data): PurchaseInvoice
    {
        $manual = $invoice ? blank($invoice->purchase_order_id) : blank($data['purchase_order_id'] ?? null);

        if ($manual) {
            ItemsGrid::ensureMainItemsHaveSubItems($data['items'] ?? []);
        }

        return $invoice ? $this->update($invoice, $data) : $this->create($data);
    }

    /**
     * زر «ترحيل الفاتورة»: تُحفظ المسودة بما أُدخل اولاً ثم تُرحَّل. الحفظ يبقى ولو رُفض الترحيل
     * (سعر ناقص مثلاً)، كما كانت الصفحة.
     */
    public function saveAndPost(PurchaseInvoice $invoice, ?array $data = null): PurchaseInvoice
    {
        if ($data !== null && $invoice->canBeEdited()) {
            $invoice = $this->saveFromEntry($invoice, $data);
        }

        return $this->post($invoice);
    }

    /**
     * ما تُعبأ به صفحة اضافة الفاتورة عند اختيار طلبية: الطرف والمخزن والعملة والضريبة والخصم
     * وسعر الصرف في تاريخ الفاتورة، وسطور الطلبية (بقطعها ووزنها الفعلي) صفوفاً في جدول الاصناف.
     *
     * @return array<string, mixed>
     */
    public function orderEntry(PurchaseOrder $order, ?string $invoiceDate = null): array
    {
        $draft = $this->draftFromOrder($order);
        $rate = app(CurrencyExchangeRateService::class)->rateFor($draft['currency_id'], $invoiceDate ?: now()->toDateString());
        $labels = ProductSearch::labels(collect($draft['items'])->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $format = fn (mixed $value): string => number_format((float) ($value ?? 0), 4, '.', '');

        return [
            'purchase_order_id' => $order->id,
            'client_id' => $order->client_id,
            'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id,
            'currency_id' => $draft['currency_id'],
            'tax_id' => $draft['tax_id'],
            'invoice_discount' => $format($draft['invoice_discount']),
            'exchange_rate' => $rate ? number_format($rate, 8, '.', '') : null,
            'items' => collect($draft['items'])->values()->map(function (array $line) use ($labels, $format): array {
                $label = $labels[$line['product_id']] ?? ProductSearch::label($line['product_id']);

                return [
                    '_key' => 'order-item-'.$line['purchase_order_item_id'],
                    'id' => null,
                    'purchase_order_item_id' => $line['purchase_order_item_id'],
                    'product_id' => $line['product_id'],
                    'product_label' => $label,
                    'product_search' => $label,
                    'barcode' => $line['barcode'] ?? null,
                    'description' => $line['description'] ?? null,
                    'quantity' => $format($line['quantity']),
                    'unit_price' => $format($line['unit_price'] ?? 0),
                    'discount_mode' => 'rate',
                    'discount_rate' => $format($line['discount_rate'] ?? 0),
                    'discount_amount' => $format($line['discount_amount'] ?? 0),
                    'line_total' => $format(0),
                    'notes' => $line['notes'] ?? null,
                    // صنف الوزن ينتقل باصنافه الفرعية ووزنه الفعلي، والا حُفظ بلا اصناف فرعية.
                    'actual_weight' => $line['actual_weight'] ?? null,
                    'pieces' => $line['pieces'] ?? [],
                ];
            })->all(),
        ];
    }

    /**
     * عرض السعر الذي تُفوتر الطلبية باسعاره. عرض مسودة يجب ان يُعتمد (او يُلغى) اولاً،
     * حتى لا تُفوتر الطلبية باسعار تخالف عرضاً ما زال قيد التفاوض.
     */
    public function invoiceableQuotation(PurchaseOrder $order): ?PurchaseQuotation
    {
        $quotation = $order->activePurchaseQuotation();

        if (! $quotation) {
            return null;
        }

        if ($quotation->isDraft()) {
            throw new DomainException(__('This purchase order has a draft purchase quotation :reference. Approve or cancel it before invoicing.', [
                'reference' => $quotation->reference_no,
            ]));
        }

        return $quotation->isApproved() ? $quotation : null;
    }

    public function update(PurchaseInvoice $invoice, array $data): PurchaseInvoice
    {
        if (! $invoice->canBeEdited()) {
            throw new DomainException(__('Only draft purchase invoices can be updated.'));
        }

        return DB::transaction(function () use ($invoice, $data) {
            $order = $this->purchaseOrder($data['purchase_order_id'] ?? $invoice->purchase_order_id);
            $items = $this->normalizeItems($data['items'] ?? []);
            $data = $this->normalizeData($data, $order, false);
            unset($data['reference_no'], $data['created_by'], $data['journal_entry_id']);

            $data = array_merge($data, $this->calculateTotals($items, $data['invoice_discount'], $data['tax_rate']));

            $invoice->update($data);
            $this->syncItems($invoice, $items);

            return $invoice->refresh()->load(['purchaseOrder', 'client', 'branch', 'warehouse', 'currency', 'tax', 'items.product']);
        });
    }

    public function delete(PurchaseInvoice $invoice): void
    {
        if (! $invoice->canBeEdited()) {
            throw new DomainException(__('Only draft purchase invoices can be deleted.'));
        }

        DB::transaction(function () use ($invoice) {
            $invoice->load(['journalEntry.lines', 'purchaseOrder.purchaseInvoices', 'purchaseQuotation']);
            $purchaseOrder = $invoice->purchaseOrder;
            $quotation = $invoice->purchaseQuotation;

            if ($invoice->journalEntry) {
                $invoice->journalEntry->lines()->delete();
                $invoice->journalEntry->delete();
            }

            $invoice->items()->delete();
            $invoice->delete();

            if ($purchaseOrder) {
                $this->restoreOrderStatus($purchaseOrder);
            }

            if ($quotation) {
                app(PurchaseQuotationService::class)->restoreAfterInvoiceRemoved($quotation);
            }
        });
    }

    /**
     * الغاء فاتورة مرحّلة (كما في ERPNext): قيد عكسي بدل الحذف، وتبقى الفاتورة للاطلاع.
     * تُمنع ان كان لها مرتجع مرحّل او دفعات مخصَّصة؛ وتعود الطلبية قابلة للفوترة.
     */
    public function cancel(PurchaseInvoice $invoice, ?string $reason = null): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $invoice = PurchaseInvoice::query()
                ->with(['journalEntry', 'purchaseOrder', 'purchaseQuotation'])
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            if (! $invoice->isPosted()) {
                throw new DomainException(__('Only posted purchase invoices can be cancelled.'));
            }

            $hasReturns = PurchaseReturn::query()
                ->where('purchase_invoice_id', $invoice->id)
                ->where('status', PurchaseReturnStatus::POSTED->value())
                ->exists();

            if ($hasReturns) {
                throw new DomainException(__('This purchase invoice has posted purchase returns. Cancel them first.'));
            }

            $allocations = app(PaymentAllocationService::class);
            $allocations->assertInvoiceHasNoAllocations($invoice);

            // تاريخ واحد لعكس الايراد والتكلفة والمخزون: تاريخ المستند ان كان شهره مفتوحاً، والا اليوم.
            $date = app(JournalEntryService::class)->postableDate($invoice->invoice_date->toDateString());

            if ($invoice->journalEntry && $invoice->journalEntry->status === JournalEntryStatus::POSTED) {
                app(JournalEntryService::class)->void($invoice->journalEntry, __('Cancellation of').' '.$invoice->reference_no, $date);
            }

            // حركة المخزون التي احدثها الترحيل تُعكس: سند الاستلام التلقائي، او دخول اصناف
            // الفاتورة بلا طلبية (يُمنع ان كانت البضاعة قد صُرفت).
            app(InvoiceStockService::class)->cancelPurchaseInvoice($invoice, $date);

            $invoice->update([
                'status' => PurchaseInvoiceStatus::CANCELLED->value(),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => filled($reason) ? $reason : null,
            ]);

            $allocations->refreshInvoice($invoice);

            if ($invoice->purchaseOrder) {
                $this->restoreOrderStatus($invoice->purchaseOrder->refresh());
            }

            if ($invoice->purchaseQuotation) {
                app(PurchaseQuotationService::class)->restoreAfterInvoiceRemoved($invoice->purchaseQuotation->refresh());
            }

            return $invoice->refresh()->load(['purchaseOrder', 'client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'journalEntry']);
        });
    }

    /**
     * الطلبية المفوترة تعود «مستلمة» (او «مؤكدة» ان لم يكتمل استلامها) حين لا تبقى لها
     * فاتورة غير ملغاة، فتُفوتر من جديد.
     */
    private function restoreOrderStatus(PurchaseOrder $order): void
    {
        if ($order->status !== PurchaseOrderStatus::INVOICED || $order->activePurchaseInvoices()->exists()) {
            return;
        }

        $received = $order->isDirectShipment()
            ? $order->received_at !== null
            : app(GoodsReceiptService::class)->isFullyReceived($order);

        $order->update([
            'status' => ($received ? PurchaseOrderStatus::RECEIVED : PurchaseOrderStatus::CONFIRMED)->value(),
            'invoiced_at' => null,
        ]);
    }

    public function post(PurchaseInvoice $invoice): PurchaseInvoice
    {
        if (! $invoice->canBePosted()) {
            throw new DomainException(__('Only draft purchase invoices can be posted.'));
        }

        return DB::transaction(function () use ($invoice) {
            $invoice = PurchaseInvoice::query()
                ->with(['items', 'journalEntry'])
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            // يُعاد الفحص بعد القفل: طلبان متزامنان يتجاوزان الفحص الاول معاً فتُرحَّل الفاتورة مرتين.
            if (! $invoice->canBePosted()) {
                throw new DomainException(__('Only draft purchase invoices can be posted.'));
            }

            if ($invoice->items->isEmpty() || (float) $invoice->total_amount <= 0) {
                throw new DomainException(__('Purchase invoice must have at least one item before posting.'));
            }

            // سطور الطلبية تصل الفاتورة بلا سعر حين لا يوجد عرض سعر؛ لا تُرحَّل قبل تسعيرها
            // والا دخلت البضاعة المخزون بلا كلفة.
            $unpriced = $invoice->items
                ->filter(fn ($item): bool => (float) $item->unit_price <= 0)
                ->map(fn ($item): string => (string) ($item->description ?: $item->product_id))
                ->values();

            if ($unpriced->isNotEmpty()) {
                throw new DomainException(__('Enter the price of every item before posting. Missing: :items', [
                    'items' => $unpriced->implode('، '),
                ]));
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
            app(InvoiceStockService::class)->receivePurchaseInvoice($invoice);

            $invoice->update(['status' => PurchaseInvoiceStatus::POSTED->value()]);
            app(PaymentAllocationService::class)->refreshInvoice($invoice);

            return $invoice->refresh()->load(['purchaseOrder', 'client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'journalEntry']);
        });
    }

    /**
     * قيم المستند المصدر (الضريبة والخصم والملاحظات) تُورَّث عند الانشاء فقط؛ عند التعديل
     * يُحفظ ما ادخله المستخدم ولو افرغ الحقل.
     */
    private function normalizeData(array $data, ?PurchaseOrder $order = null, bool $inheritFromSource = true, ?PurchaseQuotation $quotation = null): array
    {
        $invoiceDate = $data['invoice_date'] ?? now()->toDateString();
        $currencyId = (int) ($data['currency_id'] ?? $quotation?->currency_id ?? $order?->currency_id ?? app(CurrencyExchangeRateService::class)->baseCurrencyId() ?? 0);
        $sourceTax = $quotation ? $quotation->tax : $order?->tax;
        $sourceDiscount = $quotation ? $quotation->quotation_discount : $order?->order_discount;
        $tax = ! empty($data['tax_id'] ?? null) ? Tax::query()->find($data['tax_id']) : ($inheritFromSource ? $sourceTax : null);
        $exchangeRate = app(CurrencyExchangeRateService::class)->rateFor($currencyId, $invoiceDate);

        if (! $exchangeRate) {
            throw new DomainException(__('Exchange rate is missing for selected currency and invoice date.'));
        }

        return [
            'purchase_order_id' => filled($data['purchase_order_id'] ?? null) ? (int) $data['purchase_order_id'] : $order?->id,
            'client_id' => (int) ($data['client_id'] ?? $order?->client_id),
            'branch_id' => filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : $order?->branch_id,
            'warehouse_id' => filled($data['warehouse_id'] ?? null) ? (int) $data['warehouse_id'] : $order?->warehouse_id,
            'invoice_date' => $invoiceDate,
            'due_date' => $data['due_date'] ?? null,
            'currency_id' => $currencyId,
            'tax_id' => $tax?->id,
            'exchange_rate' => $this->decimalValue($exchangeRate, 1, 8),
            'invoice_discount' => $this->decimalValue($data['invoice_discount'] ?? ($inheritFromSource ? $sourceDiscount : null) ?? 0),
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
                $unitPrice = $this->decimalValue($item['unit_price'] ?? ($product ? app(StockLedgerService::class)->averageCost((int) $product->id) : 0));

                // صنف الوزن باصنافه الفرعية: الكمية المفوترة (كغم) = الوزن الفعلي، وصفر بدونه.
                $weight = WeightPieces::line($item, $product, ['purchase_order_item_piece_id']);
                $quantity = $weight['pricing_weight'] ?? $quantity;
                $discountMode = ($item['discount_mode'] ?? 'rate') === 'amount' ? 'amount' : 'rate';
                $discountRate = $this->decimalValue($item['discount_rate'] ?? 0);
                $discountAmount = $this->decimalValue($item['discount_amount'] ?? 0);

                // سطر وزن له قطع بوزن وبلا وزن فعلي جائز بمبلغ صفر.
                if ($quantity <= 0 && $weight['total_weight'] <= 0) {
                    throw new DomainException(__('Purchase invoice item quantity must be greater than zero.'));
                }

                if ($unitPrice < 0) {
                    throw new DomainException(__('Purchase invoice item price cannot be negative.'));
                }

                if ($discountRate < 0 || $discountRate > 100) {
                    throw new DomainException(__('Purchase invoice item discount must be between 0 and 100.'));
                }

                $gross = round($quantity * $unitPrice, 4);

                if ($discountMode === 'amount') {
                    if ($discountAmount < 0) {
                        throw new DomainException(__('Purchase invoice item discount amount cannot be negative.'));
                    }

                    if ($discountAmount > $gross) {
                        throw new DomainException(__('Purchase invoice item discount amount cannot exceed item total.'));
                    }

                    $discountRate = $gross > 0 ? round(($discountAmount / $gross) * 100, 4) : 0;
                } else {
                    $discountAmount = round($gross * ($discountRate / 100), 4);
                }

                return [
                    'id' => $item['id'] ?? null,
                    'purchase_order_item_id' => filled($item['purchase_order_item_id'] ?? null) ? (int) $item['purchase_order_item_id'] : null,
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
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * سطور فاتورة من طلبية: باسعار عرض السعر المعتمد، والا بسعر الطلبية (صفر للطلبيات
     * الجديدة لانها بلا اسعار، فيُدخل السعر في الفاتورة).
     */
    private function orderItemsForInvoice(PurchaseOrder $order, ?PurchaseQuotation $quotation = null): array
    {
        if ($quotation) {
            return app(PurchaseQuotationService::class)->invoiceLines($quotation);
        }

        return $order->items
            ->map(fn ($item): array => [
                'purchase_order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'category_finish_id' => $item->category_finish_id,
                'barcode' => $item->barcode,
                'description' => $item->description,
                'quantity' => $item->invoiceQuantity(),
                'actual_weight' => $item->actual_weight,
                'unit_price' => $item->unit_price,
                'discount_rate' => $item->discount_rate,
                'discount_amount' => $item->discount_amount,
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
    public static function orderPieces(PurchaseOrderItem $item): array
    {
        return $item->pieces
            ->map(fn ($piece): array => [
                'purchase_order_item_piece_id' => $piece->id,
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

    private function purchaseOrder(mixed $orderId, bool $lockForUpdate = false): ?PurchaseOrder
    {
        if (blank($orderId)) {
            return null;
        }

        $query = PurchaseOrder::query()
            ->with(['tax', 'items', 'purchaseInvoices'])
            ->whereKey($orderId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function assertPurchaseOrderCanBeInvoiced(PurchaseOrder $order): void
    {
        if ($order->status === PurchaseOrderStatus::CANCELLED) {
            throw new DomainException(__('Cancelled purchase orders cannot be invoiced.'));
        }

        if ($order->purchaseInvoices->contains(fn (PurchaseInvoice $invoice) => $invoice->status !== PurchaseInvoiceStatus::CANCELLED)) {
            throw new DomainException(__('Purchase order already has a purchase invoice.'));
        }

        if ($order->items->isEmpty()) {
            throw new DomainException(__('Purchase order must have at least one item.'));
        }
    }

    private function calculateTotals(array $items, float $invoiceDiscount, float $taxRate): array
    {
        $subTotal = collect($items)->sum(fn(array $item) => round($item['quantity'] * $item['unit_price'], 4));
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

    private function syncItems(PurchaseInvoice $invoice, array $items): void
    {
        $incomingIds = collect($items)->pluck('id')->filter()->values();

        $invoice->items()
            ->when($incomingIds->isNotEmpty(), fn($query) => $query->whereNotIn('id', $incomingIds))
            ->when($incomingIds->isEmpty(), fn($query) => $query)
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

    private function createJournalEntry(PurchaseInvoice $invoice): JournalEntry
    {
        return app(JournalEntryService::class)->createDraft(
            $this->journalData($invoice),
            $this->journalLines($invoice)
        );
    }

    private function journalData(PurchaseInvoice $invoice): array
    {
        return [
            'entry_date' => $invoice->invoice_date?->toDateString(),
            'reference_no' => $invoice->reference_no,
            'source_module' => self::SOURCE_MODULE,
            'source_type' => 'PurchaseInvoice',
            'sourceable_id' => $invoice->id,
            'sourceable_type' => $invoice::class,
            'description' => __('Purchase invoice') . ' ' . $invoice->reference_no,
            'created_by' => $invoice->created_by ?? auth()->id(),
        ];
    }

    private function journalLines(PurchaseInvoice $invoice): array
    {
        $purchaseAmount = round((float) $invoice->total_amount - (float) $invoice->tax_amount, 4);

        // فاتورة طلبية للمخزن تُقفل «بضاعة مستلمة لم تصل فواتيرها»، وطلبية الشحن المباشر
        // تُحمَّل على تكلفة البضاعة المباعة، واصناف الفاتورة بلا طلبية على مخزون المستودع؛
        // وما بقي (الخدمات) على حساب المشتريات.
        $lines = app(PurchaseInvoiceStockPosting::class)->debitLines($invoice, $purchaseAmount) ?? [];
        // covers = ما يغطيه السطر بعملة الفاتورة (سطور المخزون بالعملة الاساسية تحمله صراحة).
        $remaining = round($purchaseAmount - collect($lines)->sum(fn (array $line) => $line['covers'] ?? ((float) $line['debit'] - (float) $line['credit'])), 4);

        if ($lines === [] || $remaining > 0) {
            $lines[] = [
                'account_id' => app(LedgerAccounts::class)->mapped(LedgerAccounts::PURCHASES_KEY),
                'branch_id' => $invoice->branch_id,
                'currency_id' => $invoice->currency_id,
                'exchange_rate' => $invoice->exchange_rate,
                'debit' => $lines === [] ? $purchaseAmount : $remaining,
                'credit' => 0,
                'description' => __('Purchase invoice') . ' ' . $invoice->reference_no,
                'created_by' => $invoice->created_by ?? auth()->id(),
            ];
        }

        if ((float) $invoice->tax_amount > 0) {
            $lines[] = [
                'account_id' => app(LedgerAccounts::class)->mapped(LedgerAccounts::PURCHASE_TAX_KEY),
                'branch_id' => $invoice->branch_id,
                'currency_id' => $invoice->currency_id,
                'exchange_rate' => $invoice->exchange_rate,
                'debit' => $invoice->tax_amount,
                'credit' => 0,
                'description' => __('Purchase invoice tax') . ' ' . $invoice->reference_no,
                'created_by' => $invoice->created_by ?? auth()->id(),
            ];
        }

        $lines[] = [
            'account_id' => app(PartyAccounts::class)->supplierAccountId($invoice->client_id),
            'branch_id' => $invoice->branch_id,
            'currency_id' => $invoice->currency_id,
            'exchange_rate' => $invoice->exchange_rate,
            'debit' => 0,
            'credit' => $invoice->total_amount,
            'description' => __('Purchase invoice payable') . ' ' . $invoice->reference_no,
            'created_by' => $invoice->created_by ?? auth()->id(),
        ];

        return $lines;
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
