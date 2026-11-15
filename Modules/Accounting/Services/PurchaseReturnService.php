<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseInvoiceItem;
use Modules\Accounting\Entities\PurchaseReturn;
use Modules\Accounting\Entities\PurchaseReturnItem;
use Modules\Accounting\Entities\PurchaseReturnItemPiece;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PurchaseReturnStatus;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Support\ReturnPieces;
use Modules\Accounting\Support\WeightPieces;
use Modules\Core\Services\ReferenceNumberService;

/**
 * مرتجع المشتريات: يُرجع للمورد جزءاً من فاتورة شراء مرحّلة.
 *
 * - المورد والعملة وسعر الصرف والضريبة والاسعار من الفاتورة نفسها؛ وكل سطر يشير الى سطر
 *   الفاتورة ولا تتجاوز كميته (المفوتر − المرتجع في مرتجعات مرحّلة اخرى).
 * - صنف الوزن يُرجع اصنافاً فرعية بعدد قطع (ReturnPieces): تخرج كل قطعة بعددها بلا قيمة، ويخرج
 *   الكغم المرتجع من صنفها الرئيسي بكلفته.
 * - الترحيل قيد واحد: من حـ/ المورد (الاجمالي) الى حـ/ ضريبة المشتريات، والباقي حسب الفاتورة:
 *   · طلبية للمخزن او فاتورة بلا طلبية: تخرج البضاعة من المخزن (StockLedgerService) بتكلفة
 *     FIFO الى حـ/ مخزون المستودع، والفرق بين صافي المرتجع وكلفة المخزون الى «فروقات اسعار
 *     المشتريات»؛ والخدمات الى حـ/ المشتريات.
 *   · طلبية شحن مباشر للزبون: الى حـ/ تكلفة الشحن المباشر (كما حمّلتها الفاتورة).
 * - بعد الترحيل يُخصَّص المرتجع على فاتورته تلقائياً (بحد المتبقي عليها).
 * - الالغاء: فك التخصيص، عكس حركة المخزون، وقيد عكسي.
 */
class PurchaseReturnService
{
    private const SOURCE_MODULE = 'Accounting';

    private const EPSILON = 0.00005;

    private const RELATIONS = ['purchaseInvoice', 'client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces'];

    public function create(array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data) {
            $invoice = $this->returnableInvoice($data['purchase_invoice_id'] ?? null);
            $header = $this->normalizeHeader($data, $invoice);
            $itemsInput = collect($data['items'] ?? [])->filter(fn ($item) => filled($item['purchase_invoice_item_id'] ?? null) || filled($item['product_id'] ?? null))->all();
            $items = $this->normalizeItems($invoice, $itemsInput === [] ? $this->defaultItems($invoice) : $itemsInput, null);

            $header = array_merge($header, $this->calculateTotals($invoice, $items));
            $header['reference_no'] = app(ReferenceNumberService::class)->generate('purchase_return', $header['return_date'], ['prefix' => 'PRET']);
            $header['status'] = PurchaseReturnStatus::DRAFT->value();
            $header['created_by'] = $data['created_by'] ?? auth()->id();

            $return = PurchaseReturn::create($header);
            $this->syncItems($return, $items);

            return $return->refresh()->load(self::RELATIONS);
        });
    }

    public function update(PurchaseReturn $return, array $data): PurchaseReturn
    {
        if (! $return->canBeEdited()) {
            throw new DomainException(__('Only draft purchase returns can be updated.'));
        }

        return DB::transaction(function () use ($return, $data) {
            // فاتورة المرتجع لا تتغير بعد انشائه.
            $invoice = $this->returnableInvoice($return->purchase_invoice_id);
            $header = $this->normalizeHeader($data, $invoice);
            $items = $this->normalizeItems($invoice, $data['items'] ?? [], $return->id);

            $return->update(array_merge($header, $this->calculateTotals($invoice, $items)));
            $this->syncItems($return, $items);

            return $return->refresh()->load(self::RELATIONS);
        });
    }

    public function delete(PurchaseReturn $return): void
    {
        if (! $return->canBeEdited()) {
            throw new DomainException(__('Only draft purchase returns can be deleted.'));
        }

        DB::transaction(function () use ($return) {
            $return->load('journalEntry.lines');

            if ($return->journalEntry) {
                $return->journalEntry->lines()->delete();
                $return->journalEntry->delete();
            }

            $return->items()->delete();
            $return->delete();
        });
    }

    public function post(PurchaseReturn $return): PurchaseReturn
    {
        if (! $return->canBePosted()) {
            throw new DomainException(__('Only draft purchase returns can be posted.'));
        }

        return DB::transaction(function () use ($return) {
            $return = PurchaseReturn::query()
                ->with(['items.product', 'items.pieces', 'items.purchaseInvoiceItem', 'journalEntry'])
                ->lockForUpdate()
                ->findOrFail($return->id);

            // يُعاد الفحص بعد القفل حتى لا يُرحَّل المرتجع مرتين.
            if (! $return->canBePosted()) {
                throw new DomainException(__('Only draft purchase returns can be posted.'));
            }

            if ($return->items->isEmpty() || (float) $return->total_amount <= 0) {
                throw new DomainException(__('Purchase return must have at least one item before posting.'));
            }

            // قفل الفاتورة يمنع مرتجعين متزامنين من تجاوز الكمية المفوترة.
            $invoice = $this->returnableInvoice($return->purchase_invoice_id, true);
            $this->assertWithinReturnable($invoice, $return);

            $stockCost = $this->issueStock($return, $invoice);
            $journalData = $this->journalData($return);
            $lines = $this->journalLines($return, $invoice, $stockCost);
            $journal = app(JournalEntryService::class);

            if ($return->journalEntry) {
                $journal->updateDraft($return->journalEntry, $journalData, $lines);
                $entry = $return->journalEntry->refresh();
            } else {
                $entry = $journal->createDraft($journalData, $lines);
            }

            $journal->post($entry);

            $return->update([
                'journal_entry_id' => $entry->id,
                'status' => PurchaseReturnStatus::POSTED->value(),
            ]);

            // المرتجع يسدد فاتورته اولاً؛ ما زاد يبقى رصيداً للمورد يُخصَّص من لوحة التخصيص.
            $allocations = app(PaymentAllocationService::class);
            $amount = min((float) $return->total_amount, $allocations->outstanding($invoice->refresh()));

            if ($amount > self::EPSILON) {
                $allocations->allocate($return, $invoice, $amount, $return->return_date->toDateString());
            }

            return $return->refresh()->load(array_merge(self::RELATIONS, ['journalEntry']));
        });
    }

    /**
     * زر «ترحيل المرتجع»: تُحفظ المسودة بما أُدخل اولاً ثم يُرحَّل. الحفظ يبقى ولو رُفض الترحيل.
     */
    public function saveAndPost(PurchaseReturn $return, ?array $data = null): PurchaseReturn
    {
        if ($data !== null && $return->canBeEdited()) {
            $return = $this->update($return, $data);
        }

        return $this->post($return);
    }

    /**
     * ما تُعبأ به صفحة اضافة المرتجع عند اختيار الفاتورة: الطرف والفرع والمخزن والعملة والضريبة
     * وسعر الصرف في تاريخ المرتجع، واجماليات الفاتورة للاطلاع. السطور يأخذها الانشاء (كل القابل
     * للارجاع) ويُعدَّل في صفحة المرتجع.
     *
     * @return array<string, mixed>
     */
    public function draftFromInvoice(PurchaseInvoice $invoice, ?string $returnDate = null): array
    {
        $rate = app(CurrencyExchangeRateService::class)->rateFor($invoice->currency_id, $returnDate ?: now()->toDateString());
        $format = fn (mixed $value): string => number_format((float) ($value ?? 0), 4, '.', '');

        return [
            'purchase_invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'branch_id' => $invoice->branch_id,
            'warehouse_id' => $invoice->warehouse_id,
            'currency_id' => $invoice->currency_id,
            'tax_id' => $invoice->tax_id,
            'tax_rate' => $format($invoice->tax?->rate ?? $invoice->tax_rate ?? 0),
            'exchange_rate' => $rate ? number_format($rate, 8, '.', '') : null,
            'totals' => [
                'sub_total' => $format($invoice->sub_total),
                'items_discount' => $format($invoice->items_discount),
                'invoice_discount' => $format($invoice->invoice_discount),
                'tax_amount' => $format($invoice->tax_amount),
                'total_amount' => $format($invoice->total_amount),
            ],
        ];
    }

    /**
     * الغاء مرتجع مرحّل: تُفك تخصيصاته، وتعود البضاعة الى المخزن، ويُعكس قيده.
     */
    public function cancel(PurchaseReturn $return, ?string $reason = null): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $reason) {
            $return = PurchaseReturn::query()->with('journalEntry')->lockForUpdate()->findOrFail($return->id);

            if (! $return->isPosted()) {
                throw new DomainException(__('Only posted purchase returns can be cancelled.'));
            }

            app(PaymentAllocationService::class)->deallocateAll($return);
            app(StockLedgerService::class)->reverse($return, now()->toDateString());

            if ($return->journalEntry && $return->journalEntry->status === JournalEntryStatus::POSTED) {
                app(JournalEntryService::class)->void($return->journalEntry, __('Cancellation of').' '.$return->reference_no);
            }

            $return->update([
                'status' => PurchaseReturnStatus::CANCELLED->value(),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => filled($reason) ? $reason : null,
            ]);

            return $return->refresh()->load(array_merge(self::RELATIONS, ['journalEntry']));
        });
    }

    /**
     * سطور الفاتورة مع المفوتر والمرتجع (في مرتجعات مرحّلة غير هذا) والقابل للارجاع.
     *
     * @return Collection<int, array{item: PurchaseInvoiceItem, invoiced: float, returned: float, returnable: float}>
     */
    public function returnableLines(PurchaseInvoice $invoice, ?int $exceptReturnId = null): Collection
    {
        $invoice->loadMissing(['items.product.translations', 'items.pieces']);
        $returned = $this->returnedQuantities($invoice, $exceptReturnId);

        return $invoice->items->mapWithKeys(fn (PurchaseInvoiceItem $item) => [$item->id => [
            'item' => $item,
            'invoiced' => (float) $item->quantity,
            'returned' => (float) ($returned[$item->id] ?? 0),
            'returnable' => max(round((float) $item->quantity - (float) ($returned[$item->id] ?? 0), 4), 0),
        ]]);
    }

    /**
     * صفوف جدول الاصناف لصفحة المرتجع: سطور الفاتورة القابلة للارجاع مع ما أُدخل فيه.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gridRows(PurchaseReturn $return): array
    {
        $invoice = $return->purchaseInvoice()->with(['items.product', 'items.pieces'])->firstOrFail();
        $return->loadMissing('items.pieces');

        return ReturnPieces::gridRows(
            $invoice->items,
            $return->items,
            $this->returnableLines($invoice, $return->id)->map(fn (array $line) => $line['returnable'])->all(),
            $this->returnedPieceCounts($invoice, $return->id),
            'purchase_invoice_item_id',
            'purchase_invoice_item_piece_id'
        );
    }

    private function returnableInvoice(mixed $invoiceId, bool $lock = false): PurchaseInvoice
    {
        $invoice = blank($invoiceId) ? null : PurchaseInvoice::query()
            ->with(['items.product.translations', 'items.pieces', 'purchaseOrder'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->find($invoiceId);

        if (! $invoice || ! $invoice->isPosted()) {
            throw new DomainException(__('Select a posted purchase invoice to return from.'));
        }

        return $invoice;
    }

    private function normalizeHeader(array $data, PurchaseInvoice $invoice): array
    {
        $returnDate = $data['return_date'] ?? now()->toDateString();

        if ($returnDate < $invoice->invoice_date->toDateString()) {
            throw new DomainException(__('The return date cannot be before the invoice date.'));
        }

        return [
            'purchase_invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'branch_id' => $invoice->branch_id,
            'warehouse_id' => filled($data['warehouse_id'] ?? null)
                ? (int) $data['warehouse_id']
                : ($invoice->warehouse_id ?: $invoice->purchaseOrder?->warehouse_id),
            'return_date' => $returnDate,
            'currency_id' => $invoice->currency_id,
            'tax_id' => $invoice->tax_id,
            'exchange_rate' => (float) $invoice->exchange_rate ?: 1,
            'tax_rate' => (float) $invoice->tax_rate,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * كل ما بقي قابلاً للارجاع من الفاتورة (عند انشاء المرتجع بلا سطور).
     */
    private function defaultItems(PurchaseInvoice $invoice): array
    {
        $returnedPieces = $this->returnedPieceCounts($invoice, null);

        return $this->returnableLines($invoice)
            ->filter(fn (array $line) => $line['returnable'] > 0 || ReturnPieces::allAvailable($line['item']->pieces, $returnedPieces, 'purchase_invoice_item_piece_id') !== [])
            ->map(fn (array $line) => [
                'purchase_invoice_item_id' => $line['item']->id,
                'quantity' => $line['returnable'],
                'pieces' => ReturnPieces::allAvailable($line['item']->pieces, $returnedPieces, 'purchase_invoice_item_piece_id'),
            ])
            ->values()
            ->all();
    }

    /**
     * السطر يُسعَّر من سطر الفاتورة (السعر وحصة الخصم بنسبة الكمية)، ولا يُقبل سطر خارجها.
     */
    private function normalizeItems(PurchaseInvoice $invoice, array $items, ?int $exceptReturnId): array
    {
        $plan = $this->returnableLines($invoice, $exceptReturnId);
        $returnedPieces = $this->returnedPieceCounts($invoice, $exceptReturnId);
        $normalized = [];

        foreach (array_values($items) as $item) {
            if (blank($item['purchase_invoice_item_id'] ?? null) && blank($item['product_id'] ?? null)) {
                continue;
            }

            $line = $plan->get((int) ($item['purchase_invoice_item_id'] ?? 0));

            if (! $line) {
                throw new DomainException(__('The item does not belong to the purchase invoice.'));
            }

            /** @var PurchaseInvoiceItem $source */
            $source = $line['item'];
            $quantity = $this->decimalValue($item['quantity'] ?? 0);
            $pieces = [];

            // صنف وزن باصنافه الفرعية: يُرجع بعدد قطع كل صنف فرعي، والكمية (كغم) منها.
            if ($source->pieces->isNotEmpty()) {
                $pieces = ReturnPieces::normalize($source->pieces, $returnedPieces, $item['pieces'] ?? [], 'purchase_invoice_item_piece_id', $source->description ?: (string) $source->product?->name);
                $quantity = $pieces === [] ? 0.0 : ReturnPieces::quantity($source, $pieces);
            }

            if ($quantity <= 0 && $pieces === []) {
                continue;
            }

            $this->assertQuantity($source, $quantity, $line['returnable']);

            $gross = round($quantity * (float) $source->unit_price, 4);
            $discount = (float) $source->quantity > 0
                ? min(round((float) $source->discount_amount * $quantity / (float) $source->quantity, 4), $gross)
                : 0.0;

            $normalized[] = [
                'id' => $item['id'] ?? null,
                'purchase_invoice_item_id' => $source->id,
                'product_id' => $source->product_id,
                'barcode' => $source->barcode,
                'description' => filled($item['description'] ?? null) ? $item['description'] : $source->description,
                'quantity' => $quantity,
                'total_weight' => round(array_sum(array_column($pieces, 'total_weight')), 4),
                'unit_price' => (float) $source->unit_price,
                'discount_rate' => (float) $source->discount_rate,
                'discount_amount' => $discount,
                'line_total' => max(round($gross - $discount, 4), 0),
                'notes' => $item['notes'] ?? null,
                'created_by' => $item['created_by'] ?? auth()->id(),
                'pieces' => $pieces,
            ];
        }

        if ($normalized === []) {
            throw new DomainException(__('Enter the returned quantity for at least one item.'));
        }

        return $normalized;
    }

    private function assertWithinReturnable(PurchaseInvoice $invoice, PurchaseReturn $return): void
    {
        $plan = $this->returnableLines($invoice, $return->id);
        $returnedPieces = $this->returnedPieceCounts($invoice, $return->id);

        foreach ($return->items as $item) {
            $line = $plan->get((int) $item->purchase_invoice_item_id);

            if (! $line) {
                throw new DomainException(__('The item does not belong to the purchase invoice.'));
            }

            $this->assertQuantity($line['item'], (float) $item->quantity, $line['returnable']);

            if ($item->pieces->isNotEmpty()) {
                ReturnPieces::normalize($line['item']->pieces, $returnedPieces, $item->pieces->toArray(), 'purchase_invoice_item_piece_id', $item->description ?: (string) $item->product?->name);
            }
        }
    }

    private function assertQuantity(PurchaseInvoiceItem $item, float $quantity, float $returnable): void
    {
        if ($quantity > $returnable + self::EPSILON) {
            throw new DomainException(__(':item: returned quantity (:quantity) exceeds the returnable quantity (:remaining).', [
                'item' => $item->description ?: $item->product?->name,
                'quantity' => $quantity + 0,
                'remaining' => $returnable + 0,
            ]));
        }
    }

    /**
     * @return array<int, float> purchase_invoice_item_id => الكمية المرتجعة في مرتجعات مرحّلة
     */
    private function returnedQuantities(PurchaseInvoice $invoice, ?int $exceptReturnId): array
    {
        $returns = (new PurchaseReturn)->getTable();

        return PurchaseReturnItem::query()
            ->join($returns.' as returns', 'returns.id', '=', 'purchase_return_id')
            ->where('returns.purchase_invoice_id', $invoice->id)
            ->where('returns.status', PurchaseReturnStatus::POSTED->value())
            ->whereNull('returns.deleted_at')
            ->when($exceptReturnId, fn ($query) => $query->where('returns.id', '!=', $exceptReturnId))
            ->selectRaw('purchase_invoice_item_id, SUM(quantity) as quantity')
            ->groupBy('purchase_invoice_item_id')
            ->pluck('quantity', 'purchase_invoice_item_id')
            ->map(fn ($quantity) => (float) $quantity)
            ->all();
    }

    /**
     * @return array<int, float> purchase_invoice_item_piece_id => القطع المرتجعة في مرتجعات مرحّلة
     */
    private function returnedPieceCounts(PurchaseInvoice $invoice, ?int $exceptReturnId): array
    {
        $returns = (new PurchaseReturn)->getTable();
        $items = (new PurchaseReturnItem)->getTable();

        return PurchaseReturnItemPiece::query()
            ->join($items.' as return_items', 'return_items.id', '=', 'purchase_return_item_id')
            ->join($returns.' as returns', 'returns.id', '=', 'return_items.purchase_return_id')
            ->where('returns.purchase_invoice_id', $invoice->id)
            ->where('returns.status', PurchaseReturnStatus::POSTED->value())
            ->whereNull('returns.deleted_at')
            ->whereNull('return_items.deleted_at')
            ->when($exceptReturnId, fn ($query) => $query->where('returns.id', '!=', $exceptReturnId))
            ->selectRaw('purchase_invoice_item_piece_id, SUM(piece_count) as piece_count')
            ->groupBy('purchase_invoice_item_piece_id')
            ->pluck('piece_count', 'purchase_invoice_item_piece_id')
            ->map(fn ($count) => (float) $count)
            ->all();
    }

    /**
     * خصم الفاتورة العام يوزَّع على المرتجع بنسبة صافي سطوره الى صافي سطور الفاتورة.
     */
    private function calculateTotals(PurchaseInvoice $invoice, array $items): array
    {
        $subTotal = round(collect($items)->sum(fn (array $item) => round($item['quantity'] * $item['unit_price'], 4)), 4);
        $itemsDiscount = round(collect($items)->sum('discount_amount'), 4);
        $net = max(round($subTotal - $itemsDiscount, 4), 0);
        $invoiceNet = round((float) $invoice->sub_total - (float) $invoice->items_discount, 4);
        $discount = $invoiceNet > 0 ? min(round((float) $invoice->invoice_discount * $net / $invoiceNet, 4), $net) : 0.0;
        $taxable = max(round($net - $discount, 4), 0);
        $taxAmount = round($taxable * ((float) $invoice->tax_rate / 100), 4);

        return [
            'sub_total' => $subTotal,
            'items_discount' => $itemsDiscount,
            'invoice_discount' => $discount,
            'tax_amount' => $taxAmount,
            'total_amount' => round($taxable + $taxAmount, 4),
        ];
    }

    private function syncItems(PurchaseReturn $return, array $items): void
    {
        $keep = collect($items)->pluck('id')->filter()->values();
        $return->items()->when($keep->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $keep))->delete();

        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $pieces = $item['pieces'] ?? [];
            unset($item['id'], $item['pieces']);

            if ($id && $existing = $return->items()->whereKey($id)->first()) {
                $existing->update($item);
            } else {
                $existing = $return->items()->create($item);
            }

            WeightPieces::sync($existing->pieces(), $pieces);
        }
    }

    /**
     * يُخرج بضاعة المرتجع من المخزن بتكلفة FIFO ويحفظ كلفة الوحدة على السطر.
     * يعيد اجمالي الكلفة بالعملة الاساسية (صفر لطلبية الشحن المباشر او الخدمات).
     */
    private function issueStock(PurchaseReturn $return, PurchaseInvoice $invoice): float
    {
        $order = $invoice->purchaseOrder;

        if ($order?->isDirectShipment()) {
            return 0.0;
        }

        $factors = $order ? [] : $this->noOrderStockFactors($invoice);
        $ledger = app(StockLedgerService::class);
        $date = $return->return_date->toDateString();
        $total = 0.0;

        foreach ($return->items as $item) {
            $product = $item->product;

            if (! $product || $product->isService()) {
                continue;
            }

            // صنف الوزن: يخرج الكغم المرتجع من صنفه الرئيسي بكلفته، وكل قطعة بعددها بلا قيمة.
            if ($item->pieces->isNotEmpty()) {
                $common = [
                    'warehouse_id' => $this->stockWarehouseId($return),
                    'type' => StockMovementType::PURCHASE_RETURN,
                    'date' => $date,
                    'source' => $return,
                    'source_line_id' => $item->id,
                    'reference_no' => $return->reference_no,
                    'description' => __('Purchase return').' '.$return->reference_no,
                ];
                $cost = 0.0;

                if ((float) $item->quantity > 0) {
                    $movements = $ledger->issue(array_merge($common, [
                        'product_id' => $product->id,
                        'quantity' => round((float) $item->quantity, 4),
                        'weight' => round((float) $item->quantity, 4),
                    ]));
                    $cost = round((float) $movements->sum(fn (StockMovement $movement) => -(float) $movement->total_cost), 4);
                    $item->update(['unit_cost' => round($cost / (float) $item->quantity, 6)]);
                }

                foreach ($item->pieces as $piece) {
                    $ledger->issue(array_merge($common, [
                        'product_id' => $piece->product_id,
                        'quantity' => round((float) $piece->piece_count, 4),
                        'weight' => (float) $piece->total_weight > 0 ? round((float) $piece->total_weight, 4) : null,
                    ]));
                }

                $total += $cost;

                continue;
            }

            $factor = $order
                ? $this->orderStockFactor($item->purchaseInvoiceItem, $product)
                : ($factors[(int) $item->purchase_invoice_item_id] ?? ['quantity' => 0.0, 'weight' => 0.0]);
            $quantity = round((float) $item->quantity * $factor['quantity'], 4);

            if ($quantity <= 0) {
                continue;
            }

            $warehouseId = $this->stockWarehouseId($return);
            $movements = $ledger->issue([
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'weight' => $factor['weight'] > 0 ? round((float) $item->quantity * $factor['weight'], 4) : null,
                'type' => StockMovementType::PURCHASE_RETURN,
                'date' => $date,
                'source' => $return,
                'source_line_id' => $item->id,
                'reference_no' => $return->reference_no,
                'description' => __('Purchase return').' '.$return->reference_no,
            ]);

            $cost = round((float) $movements->sum(fn (StockMovement $movement) => -(float) $movement->total_cost), 4);
            // كلفة وحدة المرتجع (بوحدة الفاتورة) بالعملة الاساسية.
            $item->update(['unit_cost' => round($cost / (float) $item->quantity, 6)]);
            $total += $cost;
        }

        return round($total, 4);
    }

    /**
     * فاتورة بلا طلبية: نفس تحويل الفاتورة عند الادخال (الموزون بالكغم يُخزَّن بالقطع).
     *
     * @return array<int, array{quantity: float, weight: float}> لكل سطر فاتورة: قطع ووزن لكل وحدة مفوترة
     */
    private function noOrderStockFactors(PurchaseInvoice $invoice): array
    {
        $quantities = $invoice->items->pluck('quantity', 'id');
        $factors = [];

        foreach (app(InvoiceStockService::class)->stockLines($invoice) as $line) {
            // سطر الوزن يعطي صف صنفه الرئيسي ايضاً؛ العامل هنا لصف صنف السطر نفسه.
            if (isset($factors[$line['item_id']])) {
                continue;
            }

            $invoiced = (float) ($quantities[$line['item_id']] ?? 0);

            if ($invoiced > 0) {
                $factors[$line['item_id']] = ['quantity' => $line['quantity'] / $invoiced, 'weight' => $line['weight'] / $invoiced];
            }
        }

        return $factors;
    }

    /**
     * فاتورة طلبية للمخزن: البند استُلم بعدد قطعه (quantity) وفوتر بوزنه او عدده.
     * البند المستلم بقطع اصناف فرعية لا يُرجع من هنا لان كمية كل قطعة غير معروفة.
     *
     * @return array{quantity: float, weight: float}
     */
    private function orderStockFactor(?PurchaseInvoiceItem $invoiceItem, $product): array
    {
        $orderItem = $invoiceItem?->purchaseOrderItem;

        if ($orderItem && $orderItem->pieces()->exists()) {
            throw new DomainException(__(':item was received as sub-item pieces; record the returned pieces with a stock adjustment instead.', [
                'item' => $invoiceItem->description ?: $product->name,
            ]));
        }

        if ($orderItem) {
            $invoiced = $orderItem->invoiceQuantity();

            return ['quantity' => $invoiced > 0 ? (float) $orderItem->quantity / $invoiced : 1.0, 'weight' => 0.0];
        }

        if ($product->pricing_type === 'weight') {
            $perPiece = round((float) $product->length * (float) $product->weight_per_meter, 4);

            if ($perPiece <= 0) {
                throw new DomainException(__(':item is priced by weight; set its piece length and weight per meter so the weight can be converted to pieces.', [
                    'item' => $product->name,
                ]));
            }

            return ['quantity' => 1 / $perPiece, 'weight' => 1.0];
        }

        return ['quantity' => 1.0, 'weight' => 0.0];
    }

    private function stockWarehouseId(PurchaseReturn $return): int
    {
        if (! $return->warehouse_id) {
            throw new DomainException(__('Select the warehouse that the returned goods leave from.'));
        }

        return (int) $return->warehouse_id;
    }

    private function journalData(PurchaseReturn $return): array
    {
        return [
            'entry_date' => $return->return_date?->toDateString(),
            'reference_no' => $return->reference_no,
            'source_module' => self::SOURCE_MODULE,
            'source_type' => 'PurchaseReturn',
            'sourceable_id' => $return->id,
            'sourceable_type' => $return::class,
            'description' => __('Purchase return').' '.$return->reference_no,
            'created_by' => $return->created_by ?? auth()->id(),
        ];
    }

    /**
     * سطور العملة الاساسية (المخزون والفرق) تُحسب بحيث يتوازن القيد تماماً بعد تقريب
     * JournalEntryService لكل سطر بعملة المرتجع، فيُعكس عند الالغاء بلا فروق.
     */
    private function journalLines(PurchaseReturn $return, PurchaseInvoice $invoice, float $stockCost): array
    {
        $rate = (float) $return->exchange_rate ?: 1;
        $total = round((float) $return->total_amount, 4);
        $tax = round((float) $return->tax_amount, 4);
        $net = round($total - $tax, 4);
        $description = __('Purchase return').' '.$return->reference_no;

        $line = fn (int $accountId, float $debit, float $credit, ?string $text = null, bool $base = false) => [
            'account_id' => $accountId,
            'branch_id' => $return->branch_id,
            'currency_id' => $base ? (app(CurrencyExchangeRateService::class)->baseCurrencyId() ?? $return->currency_id) : $return->currency_id,
            'exchange_rate' => $base ? 1 : $rate,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $text ?? $description,
            'created_by' => $return->created_by ?? auth()->id(),
        ];

        $lines = [$line(app(PartyAccounts::class)->supplierAccountId($return->client_id), $total, 0, __('Purchase return payable').' '.$return->reference_no)];

        if ($tax > 0) {
            $lines[] = $line(app(LedgerAccounts::class)->mapped(LedgerAccounts::PURCHASE_TAX_KEY), 0, $tax, __('Purchase return tax').' '.$return->reference_no);
        }

        if ($invoice->purchaseOrder?->isDirectShipment()) {
            if ($net > 0) {
                $lines[] = $line(app(InventoryAccounts::class)->mapped(InventoryAccounts::DIRECT_COST_KEY), 0, $net);
            }

            return $lines;
        }

        // الخدمات (وكل الصافي ان لم تخرج بضاعة من المخزن) الى حساب المشتريات.
        $purchases = $stockCost > 0 ? min($this->serviceAmount($return), $net) : $net;

        if ($purchases > 0) {
            $lines[] = $line(app(LedgerAccounts::class)->mapped(LedgerAccounts::PURCHASES_KEY), 0, $purchases);
        }

        if ($stockCost <= 0) {
            return $lines;
        }

        $accounts = app(InventoryAccounts::class);
        $goodsBase = round(
            $this->toBase($total, $rate) - ($tax > 0 ? $this->toBase($tax, $rate) : 0) - ($purchases > 0 ? $this->toBase($purchases, $rate) : 0),
            4
        );
        $variance = round($goodsBase - $stockCost, 4);

        $lines[] = $line($accounts->warehouseAccountId((int) $return->warehouse_id), 0, $stockCost, null, true);

        if ($variance != 0.0) {
            $lines[] = $line(
                $accounts->mapped(InventoryAccounts::PRICE_VARIANCE_KEY),
                $variance < 0 ? -$variance : 0,
                $variance > 0 ? $variance : 0,
                __('Purchase price variance').' '.$return->reference_no,
                true
            );
        }

        return $lines;
    }

    /**
     * صافي سطور الخدمات في المرتجع بعد حصته من خصم الفاتورة (بعملة المرتجع).
     */
    private function serviceAmount(PurchaseReturn $return): float
    {
        $linesTotal = (float) $return->items->sum('line_total');

        if ($linesTotal <= 0) {
            return 0.0;
        }

        $factor = max(($linesTotal - (float) $return->invoice_discount) / $linesTotal, 0);

        return round((float) $return->items
            ->filter(fn (PurchaseReturnItem $item) => $item->product?->isService())
            ->sum(fn (PurchaseReturnItem $item) => (float) $item->line_total * $factor), 4);
    }

    private function toBase(float $amount, float $rate): float
    {
        return round(round($amount, 4) * round($rate, 8), 4);
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
