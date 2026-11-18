<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\GoodsReceiptItem;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\PurchaseOrderDeliveryType;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\PurchaseQuotationStatus;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\Concerns\PostsInventoryJournal;
use Modules\Accounting\Support\MainItemShare;
use Modules\Core\Services\ReferenceNumberService;

/**
 * سند الاستلام: يُدخل بضاعة طلبية الشراء الى المخزن.
 *
 * - يقبل الاستلام الجزئي؛ المتبقي = المطلوب − المستلم في سندات اخرى غير ملغاة.
 * - الصنف الموزون يُستلم بقطعه (الاصناف الفرعية) كل قطعة بعددها ووزنها المحسوب بقيمة صفر،
 *   ومعها حركة صنفها الرئيسي: الكغم = الوزن الفعلي في الطلبية ان وُجد والا الوزن المحسوب،
 *   بنسبة ما استلمه السند من القطع.
 * - القيمة = قيمة سطر الطلبية من lineValues() (سعر عرض الشراء المعتمد، او سعر الطلبية، او كلفة
 *   مؤقتة) على حركة الصنف الرئيسي؛ فتتطابق مع ما تُقفله فاتورة الشراء من حساب «بضاعة لم تصل
 *   فواتيرها». الفاتورة بوزن فعلي او مبلغ مختلف تسوّي الفرق (InvoiceStockService).
 */
class GoodsReceiptService
{
    use PostsInventoryJournal;

    private const RELATIONS = ['purchaseOrder', 'client', 'warehouse', 'items.product', 'items.location', 'journalEntry'];

    public function create(array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data) {
            $order = $this->receivableOrder((int) ($data['purchase_order_id'] ?? 0));
            $header = $this->normalizeHeader($data, $order);
            $items = $this->normalizeItems($order, $data['items'] ?? [], null);

            $header['reference_no'] = app(ReferenceNumberService::class)->generate('goods_receipt', $header['receipt_date'], ['prefix' => 'GRN']);
            $header['status'] = InventoryDocumentStatus::DRAFT->value();
            $header['created_by'] = $data['created_by'] ?? auth()->id();

            $receipt = GoodsReceipt::create(array_merge($header, $this->totals($items, $order, null)));
            $this->syncItems($receipt, $items);

            return $receipt->refresh()->load(self::RELATIONS);
        });
    }

    public function update(GoodsReceipt $receipt, array $data): GoodsReceipt
    {
        if (! $receipt->canBeEdited()) {
            throw new DomainException(__('Only draft documents can be updated.'));
        }

        return DB::transaction(function () use ($receipt, $data) {
            $order = $this->receivableOrder((int) $receipt->purchase_order_id);
            $header = $this->normalizeHeader($data, $order);
            $items = $this->normalizeItems($order, $data['items'] ?? [], $receipt->id);

            // فاتورة الشراء المرحّلة أقفلت حساب «بضاعة لم تصل فواتيرها» بقيمة هذا السند.
            $totals = $this->totals($items, $order, $receipt->id);

            if ($this->orderHasPostedInvoice($order->id) && round($totals['total_cost'] - (float) $receipt->total_cost, 4) != 0.0) {
                throw new DomainException(__('This receipt is already covered by a posted purchase invoice; its quantities cannot change.'));
            }

            $receipt->update(array_merge($header, $totals));
            $this->syncItems($receipt, $items);

            return $receipt->refresh()->load(self::RELATIONS);
        });
    }

    public function delete(GoodsReceipt $receipt): void
    {
        if (! $receipt->canBeEdited()) {
            throw new DomainException(__('Only draft documents can be deleted.'));
        }

        if ($this->orderHasPostedInvoice((int) $receipt->purchase_order_id)) {
            throw new DomainException(__('This receipt is already covered by a posted purchase invoice; post it instead of deleting it.'));
        }

        DB::transaction(function () use ($receipt) {
            $receipt->items()->delete();
            $receipt->delete();
        });
    }

    public function post(GoodsReceipt $receipt): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt) {
            $receipt = GoodsReceipt::query()->with(['items.product', 'warehouse'])->lockForUpdate()->findOrFail($receipt->id);

            if (! $receipt->isDraft()) {
                throw new DomainException(__('Only draft documents can be posted.'));
            }

            if ($receipt->items->isEmpty()) {
                throw new DomainException(__('The document must have at least one item.'));
            }

            $order = $this->receivableOrder((int) $receipt->purchase_order_id, true);
            $this->assertWithinOutstanding($order, $receipt->items, $receipt->id);

            $ledger = app(StockLedgerService::class);
            $layout = app(WarehouseLayoutService::class);

            foreach ($receipt->items as $item) {
                // سطر بلا موقع يدخل الموقع الاساسي للصنف في هذا المخزن (ان كان له موقع).
                if (! $item->location_id && $primary = $layout->primaryLocationId($item->product_id, $receipt->warehouse_id)) {
                    $item->update(['location_id' => $primary]);
                }

                $ledger->receive([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $receipt->warehouse_id,
                    'location_id' => $item->location_id,
                    'quantity' => (float) $item->quantity,
                    'weight' => (float) $item->weight,
                    'unit_cost' => (float) $item->unit_cost,
                    'type' => StockMovementType::RECEIPT,
                    'date' => $receipt->receipt_date->toDateString(),
                    'source' => $receipt,
                    'source_line_id' => $item->id,
                    'reference_no' => $receipt->reference_no,
                    'description' => __('Goods Receipt').' '.$receipt->reference_no,
                ]);

                if ($item->location_id) {
                    $layout->rememberLocation($item->product_id, $receipt->warehouse_id, (int) $item->location_id);
                }
            }

            // حركة الصنف الرئيسي لكل سطر وزن: الكغم والقيمة، والقطع بلا قيمة.
            $mainValue = 0.0;

            foreach ($this->mainRows($order, $receipt->items, $receipt->id, true) as $row) {
                if ($row['kg'] <= 0) {
                    continue;
                }

                $ledger->receive([
                    'product_id' => $row['product_id'],
                    'warehouse_id' => $receipt->warehouse_id,
                    'location_id' => $layout->primaryLocationId($row['product_id'], $receipt->warehouse_id),
                    'quantity' => $row['kg'],
                    'weight' => $row['kg'],
                    'unit_cost' => round($row['value'] / $row['kg'], 6),
                    'total_cost' => $row['value'],
                    'type' => StockMovementType::RECEIPT,
                    'date' => $receipt->receipt_date->toDateString(),
                    'source' => $receipt,
                    'source_line_id' => $row['line_id'],
                    'reference_no' => $receipt->reference_no,
                    'description' => __('Goods Receipt').' '.$receipt->reference_no,
                ]);

                $mainValue += $row['value'];
            }

            $accounts = app(InventoryAccounts::class);
            $total = round((float) $receipt->items->sum('total_cost') + $mainValue, 4);
            $description = __('Goods Receipt').' '.$receipt->reference_no.' - '.$order->reference_no;
            $entry = $this->postInventoryJournal($receipt, 'GoodsReceipt', $receipt->receipt_date->toDateString(), $description, [
                ['account_id' => $accounts->warehouseAccountId($receipt->warehouse), 'debit' => $total, 'credit' => 0],
                ['account_id' => $accounts->mapped(InventoryAccounts::GRNI_KEY), 'debit' => 0, 'credit' => $total],
            ]);

            $receipt->update([
                'status' => InventoryDocumentStatus::POSTED->value(),
                'total_cost' => $total,
                'journal_entry_id' => $entry?->id,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            $this->refreshOrderStatus($order);

            return $receipt->refresh()->load(self::RELATIONS);
        });
    }

    public function cancel(GoodsReceipt $receipt): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt) {
            $receipt = GoodsReceipt::query()->with('journalEntry')->lockForUpdate()->findOrFail($receipt->id);

            if (! $receipt->isPosted()) {
                throw new DomainException(__('Only posted documents can be cancelled.'));
            }

            // الغاؤه بعد الفوترة يترك رصيداً في «بضاعة لم تصل فواتيرها»؛ تُلغى الفاتورة اولاً.
            if ($this->orderHasPostedInvoice((int) $receipt->purchase_order_id)) {
                throw new DomainException(__('The purchase order of this receipt has a posted purchase invoice. Cancel the invoice first.'));
            }

            app(StockLedgerService::class)->reverse($receipt, now()->toDateString());
            $this->voidInventoryJournal($receipt, __('Cancellation of').' '.$receipt->reference_no);

            $receipt->update([
                'status' => InventoryDocumentStatus::CANCELLED->value(),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
            ]);

            $this->refreshOrderStatus(PurchaseOrder::query()->with('items.pieces')->findOrFail($receipt->purchase_order_id));

            return $receipt->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * يستلم كل المتبقي من الطلبية بسند واحد مرحّل (عند ترحيل فاتورة الشراء) حتى لا تبقى
     * بضاعة مفوترة خارج المخزون. يعيد null اذا لم يبقَ شيء؛ سندات المسودة تبقى لمرحّلها.
     */
    public function receiveOutstanding(PurchaseOrder $order, array $header): ?GoodsReceipt
    {
        $items = $this->outstandingLines($order)
            ->filter(fn (array $line) => $line['outstanding'] > 0)
            ->map(fn (array $line) => [
                'purchase_order_item_id' => $line['purchase_order_item_id'],
                'purchase_order_item_piece_id' => $line['purchase_order_item_piece_id'],
                'quantity' => $line['outstanding'],
            ])
            ->values()
            ->all();

        if ($items === []) {
            return null;
        }

        $receipt = $this->create(array_merge($header, ['purchase_order_id' => $order->id, 'items' => $items]));

        if (filled($header['purchase_invoice_id'] ?? null)) {
            $receipt->update(['purchase_invoice_id' => (int) $header['purchase_invoice_id']]);
        }

        return $this->post($receipt);
    }

    /**
     * الغاء سند استلام انشأته فاتورة شراء تُلغى الآن: يعكس الحركة والقيد بتاريخ الالغاء.
     * لا يمر بشرط «الغِ الفاتورة اولاً» لانه جزء من الغائها.
     */
    public function cancelForInvoice(GoodsReceipt $receipt, string $date): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $date) {
            $receipt = GoodsReceipt::query()->with('journalEntry')->lockForUpdate()->findOrFail($receipt->id);

            if (! $receipt->isPosted()) {
                return $receipt;
            }

            app(StockLedgerService::class)->reverse($receipt, $date);
            $this->voidInventoryJournal($receipt, __('Cancellation of').' '.$receipt->reference_no, $date);

            $receipt->update([
                'status' => InventoryDocumentStatus::CANCELLED->value(),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
            ]);

            $this->refreshOrderStatus(PurchaseOrder::query()->with('items.pieces')->findOrFail($receipt->purchase_order_id));

            return $receipt->refresh();
        });
    }

    /**
     * سطور الطلبية القابلة للاستلام مع المطلوب والمستلم والمتبقي وكلفة الوحدة.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function outstandingLines(PurchaseOrder $order, ?int $exceptReceiptId = null, bool $postedOnly = false): Collection
    {
        $order->loadMissing(['items.product.translations', 'items.pieces.product.translations']);
        $received = $this->receivedQuantities($order, $exceptReceiptId, $postedOnly);
        $values = $this->lineValues($order);
        $lines = collect();

        foreach ($order->items as $item) {
            if (! $item->product || $item->product->isService()) {
                continue;
            }

            $lineValue = $values[$item->id] ?? 0.0;

            if ($item->pieces->isNotEmpty()) {
                foreach ($item->pieces as $piece) {
                    $count = (float) $piece->piece_count;

                    if ($count <= 0 || ! $piece->product_id) {
                        continue;
                    }

                    $receivedQuantity = (float) ($received['piece:'.$piece->id] ?? 0);

                    $lines->push([
                        'key' => 'piece:'.$piece->id,
                        'purchase_order_item_id' => $item->id,
                        'purchase_order_item_piece_id' => $piece->id,
                        'product_id' => $piece->product_id,
                        'barcode' => $piece->barcode ?: $piece->product?->barcode,
                        'description' => $piece->description ?: $piece->product?->name,
                        'group' => $item->description ?: $item->product->name,
                        'piece_length' => (float) $piece->piece_length,
                        'ordered' => $count,
                        'received' => $receivedQuantity,
                        'outstanding' => max(round($count - $receivedQuantity, 4), 0),
                        'weight_per_unit' => round((float) $piece->total_weight / $count, 4),
                        // القطعة بلا قيمة؛ قيمة السطر على صنفه الرئيسي (mainRows).
                        'unit_cost' => 0.0,
                    ]);
                }

                continue;
            }

            // صنف رئيسي بلا قطع يُستلم بالكغم.
            $isMainLine = app(StockLedgerService::class)->isMainItem($item->product);
            $quantity = $isMainLine ? $item->invoiceQuantity() : (float) $item->quantity;

            if ($quantity <= 0) {
                continue;
            }

            $receivedQuantity = (float) ($received['item:'.$item->id] ?? 0);

            $lines->push([
                'key' => 'item:'.$item->id,
                'purchase_order_item_id' => $item->id,
                'purchase_order_item_piece_id' => null,
                'product_id' => $item->product_id,
                'barcode' => $item->barcode ?: $item->product->barcode,
                'description' => $item->description ?: $item->product->name,
                'group' => null,
                'piece_length' => (float) $item->product->length,
                'ordered' => $quantity,
                'received' => $receivedQuantity,
                'outstanding' => max(round($quantity - $receivedQuantity, 4), 0),
                'weight_per_unit' => $isMainLine ? 1.0 : ((float) $item->total_weight > 0 ? round((float) $item->total_weight / $quantity, 4) : $this->weightPerUnit($item->product)),
                'unit_cost' => round($lineValue / $quantity, 6),
            ]);
        }

        return $lines;
    }

    /**
     * قيمة بضاعة الطلبية التي تُقيَّد (او ستُقيَّد) دائنةً في «بضاعة لم تصل فواتيرها» بالعملة
     * الاساسية، بكلفة سندات الاستلام نفسها لا بالكمية المطلوبة:
     * السندات غير الملغاة (المرحّلة والمسودة التي ستُرحَّل) + ما سيستلمه سند الاستلام
     * التلقائي عند ترحيل الفاتورة (receiveOutstanding).
     * تستعملها فاتورة الشراء لاقفال الحساب بالقيمة الفعلية فيصبح رصيده صفراً.
     */
    public function receivableInventoryValue(PurchaseOrder $order): float
    {
        $receipts = (float) GoodsReceipt::query()
            ->where('purchase_order_id', $order->id)
            ->where('status', '!=', InventoryDocumentStatus::CANCELLED->value())
            ->sum('total_cost');

        $order->unsetRelation('items');
        $outstanding = $this->outstandingLines($order)->filter(fn (array $line) => $line['outstanding'] > 0);
        $pending = $outstanding->sum(fn (array $line) => round($line['outstanding'] * $line['unit_cost'], 4));
        $pendingMain = collect($this->mainRows($order, $outstanding->map(fn (array $line) => [
            'id' => null,
            'purchase_order_item_id' => $line['purchase_order_item_id'],
            'purchase_order_item_piece_id' => $line['purchase_order_item_piece_id'],
            'quantity' => $line['outstanding'],
            'weight' => round($line['outstanding'] * $line['weight_per_unit'], 4),
        ])->values()->all(), null, false))->sum('value');

        return round($receipts + $pending + $pendingMain, 4);
    }

    /**
     * قيمة سند من سطوره: غير قطع الوزن بكلفتها، وقطع الوزن بنصيبها من قيمة سطرها.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public function documentValue(PurchaseOrder $order, iterable $rows, ?int $exceptReceiptId): float
    {
        return round((float) collect($rows)->sum('total_cost') + (float) collect($this->mainRows($order, $rows, $exceptReceiptId, false))->sum('value'), 4);
    }

    /**
     * صف الصنف الرئيسي لكل سطر وزن يعالجه السند: الكغم (الوزن الفعلي في الطلبية، والا الوزن
     * المحسوب) وقيمة السطر، بنصيب ما عالجه السند من قطع السطر (MainItemShare).
     *
     * @param  iterable<int, mixed>  $rows  سطور السند (قطعة/كمية/وزن)
     * @return array<int, array{product_id:int, kg:float, value:float, line_id:?int}>
     */
    private function mainRows(PurchaseOrder $order, iterable $rows, ?int $exceptReceiptId, bool $postedOnly): array
    {
        $grouped = collect($rows)
            ->filter(fn ($row) => filled(data_get($row, 'purchase_order_item_piece_id')))
            ->groupBy(fn ($row) => (int) data_get($row, 'purchase_order_item_id'));

        if ($grouped->isEmpty()) {
            return [];
        }

        $order->loadMissing(['items.product', 'items.pieces']);
        $values = $this->lineValues($order);
        $result = [];

        foreach ($grouped as $itemId => $itemRows) {
            $item = $order->items->firstWhere('id', $itemId);

            if (! $item) {
                continue;
            }

            $calculated = (float) $item->pieces->sum('total_weight');
            $byWeight = $calculated > 0;
            $whole = $byWeight ? $calculated : (float) $item->pieces->sum('piece_count');
            $column = $byWeight ? 'weight' : 'quantity';
            $current = (float) $itemRows->sum(fn ($row) => (float) data_get($row, $column));
            $prior = (float) GoodsReceiptItem::query()
                ->join(config('accounting.table_prefix').'goods_receipts as receipts', 'receipts.id', '=', 'goods_receipt_id')
                ->where('purchase_order_item_id', $itemId)
                ->whereNotNull('purchase_order_item_piece_id')
                ->whereNull('receipts.deleted_at')
                ->when(
                    $postedOnly,
                    fn ($query) => $query->where('receipts.status', InventoryDocumentStatus::POSTED->value()),
                    fn ($query) => $query->where('receipts.status', '!=', InventoryDocumentStatus::CANCELLED->value())
                )
                ->when($exceptReceiptId, fn ($query) => $query->where('receipts.id', '!=', $exceptReceiptId))
                ->sum($column);
            $kg = (float) $item->actual_weight > 0 ? (float) $item->actual_weight : $calculated;

            $result[] = [
                'product_id' => (int) $item->product_id,
                'kg' => MainItemShare::slice($kg, $whole, $prior, $current),
                'value' => MainItemShare::slice((float) ($values[$item->id] ?? 0), $whole, $prior, $current),
                'line_id' => data_get($itemRows->first(), 'id') ? (int) data_get($itemRows->first(), 'id') : null,
            ];
        }

        return $result;
    }

    /**
     * للطلبية فاتورة شراء مرحّلة (غير ملغاة).
     */
    public function orderHasPostedInvoice(int $orderId): bool
    {
        return $orderId > 0 && PurchaseInvoice::query()
            ->where('purchase_order_id', $orderId)
            ->where('status', PurchaseInvoiceStatus::POSTED->value())
            ->exists();
    }

    public function isFullyReceived(PurchaseOrder $order): bool
    {
        $lines = $this->outstandingLines($order, null, true);

        return $lines->isNotEmpty() && $lines->every(fn (array $line) => $line['outstanding'] <= 0);
    }

    /**
     * سطور شاشة السند = سطور الطلبية المتبقية، مع كمية هذا السند وموقعه ان كان محفوظاً.
     * سند جديد يبدأ بالمتبقي كاملاً، والسطر بلا موقع يأخذ موقع الصنف الاساسي في المخزن
     * (او كل السطور عند $overwriteLocations: تغيير المخزن). المسودة تعرض ما بقي قابلاً للاستلام،
     * والمرحّل/الملغى سطوره المحفوظة فقط.
     *
     * @return list<array<string, mixed>>
     */
    public function formLines(PurchaseOrder $order, ?GoodsReceipt $receipt = null, ?int $warehouseId = null): array
    {
        $editable = ! $receipt || $receipt->canBeEdited();
        $saved = $receipt
            ? $receipt->loadMissing('items.location')->items->keyBy(fn (GoodsReceiptItem $item) => $item->purchase_order_item_piece_id ? 'piece:'.$item->purchase_order_item_piece_id : 'item:'.$item->purchase_order_item_id)
            : collect();

        $lines = $this->outstandingLines($order, $receipt?->id, $receipt && ! $receipt->isDraft())
            ->filter(fn (array $line) => $saved->has($line['key']) || ($editable && $line['outstanding'] > 0))
            ->map(function (array $line) use ($saved, $receipt) {
                $item = $saved->get($line['key']);

                return array_merge($line, [
                    'id' => $item?->id,
                    'quantity' => $item ? (float) $item->quantity : ($receipt ? null : $line['outstanding']),
                    'location_id' => $item?->location_id,
                    'location_code' => $item?->location?->code,
                    'notes' => $item?->notes,
                    'unit_cost' => $item ? (float) $item->unit_cost : $line['unit_cost'],
                ]);
            })
            ->values()
            ->all();

        $warehouseId ??= $receipt?->warehouse_id ?? $order->warehouse_id;

        if ($editable && $warehouseId) {
            $primary = app(WarehouseLayoutService::class)->primaryLocationIds(array_column($lines, 'product_id'), (int) $warehouseId);

            foreach ($lines as $index => $line) {
                if (blank($line['location_id'])) {
                    $lines[$index]['location_id'] = $primary[$line['product_id']] ?? null;
                }
            }
        }

        return $lines;
    }

    /**
     * قيمة السند قبل حفظه لما تُدخله الشاشة من كميات (السطور بمفاتيح formLines): غير قطع
     * الوزن بكلفتها، وقطع الوزن بنصيبها من قيمة سطرها (documentValue).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function formValue(PurchaseOrder $order, array $lines, ?int $exceptReceiptId = null): float
    {
        $plan = $this->outstandingLines($order, $exceptReceiptId)->keyBy('key');
        $rows = [];

        foreach ($lines as $line) {
            $quantity = $this->decimal($line['quantity'] ?? 0);
            $key = filled($line['purchase_order_item_piece_id'] ?? null) ? 'piece:'.$line['purchase_order_item_piece_id'] : 'item:'.($line['purchase_order_item_id'] ?? 0);
            $planned = $plan->get($key);

            if ($quantity <= 0 || ! $planned) {
                continue;
            }

            $rows[] = [
                'id' => null,
                'purchase_order_item_id' => $planned['purchase_order_item_id'],
                'purchase_order_item_piece_id' => $planned['purchase_order_item_piece_id'],
                'quantity' => $quantity,
                'weight' => round($quantity * $planned['weight_per_unit'], 4),
                'total_cost' => round($quantity * $planned['unit_cost'], 4),
            ];
        }

        return $this->documentValue($order, $rows, $exceptReceiptId);
    }

    private function receivableOrder(int $orderId, bool $lock = false): PurchaseOrder
    {
        $query = PurchaseOrder::query()->with(['items.product', 'items.pieces.product'])->whereKey($orderId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $order = $query->first();

        if (! $order) {
            throw new DomainException(__('Purchase order not found.'));
        }

        if ($order->delivery_type === PurchaseOrderDeliveryType::DIRECT->value) {
            throw new DomainException(__('This purchase order is shipped directly to the customer and does not enter the warehouse.'));
        }

        if (! in_array($order->status, [PurchaseOrderStatus::CONFIRMED, PurchaseOrderStatus::RECEIVED, PurchaseOrderStatus::INVOICED], true)) {
            throw new DomainException(__('Only confirmed purchase orders can be received.'));
        }

        return $order;
    }

    private function normalizeHeader(array $data, PurchaseOrder $order): array
    {
        $warehouseId = (int) ($data['warehouse_id'] ?? $order->warehouse_id);

        if (! $warehouseId) {
            throw new DomainException(__('Please select a warehouse.'));
        }

        return [
            'purchase_order_id' => $order->id,
            'client_id' => $order->client_id,
            'branch_id' => $data['branch_id'] ?? $order->branch_id,
            'warehouse_id' => $warehouseId,
            'receipt_date' => $data['receipt_date'] ?? now()->toDateString(),
            'supplier_document_no' => $data['supplier_document_no'] ?? null,
            'container_no' => $data['container_no'] ?? null,
            'received_by_name' => $data['received_by_name'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function normalizeItems(PurchaseOrder $order, array $items, ?int $exceptReceiptId): array
    {
        $plan = $this->outstandingLines($order, $exceptReceiptId)->keyBy('key');
        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            $quantity = $this->decimal($item['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $key = filled($item['purchase_order_item_piece_id'] ?? null)
                ? 'piece:'.$item['purchase_order_item_piece_id']
                : 'item:'.($item['purchase_order_item_id'] ?? 0);
            $line = $plan->get($key);

            if (! $line) {
                throw new DomainException(__('The item does not belong to the purchase order.'));
            }

            if ($quantity > $line['outstanding'] + 0.00005) {
                throw new DomainException(__(':item: received quantity (:quantity) exceeds the remaining quantity (:remaining).', [
                    'item' => $line['description'],
                    'quantity' => $quantity + 0,
                    'remaining' => $line['outstanding'] + 0,
                ]));
            }

            $normalized[] = [
                'id' => $item['id'] ?? null,
                'purchase_order_item_id' => $line['purchase_order_item_id'],
                'purchase_order_item_piece_id' => $line['purchase_order_item_piece_id'],
                'product_id' => $line['product_id'],
                'location_id' => filled($item['location_id'] ?? null) ? (int) $item['location_id'] : null,
                'barcode' => $line['barcode'],
                'description' => $line['description'],
                'quantity' => $quantity,
                'piece_length' => $line['piece_length'],
                'weight' => round($quantity * $line['weight_per_unit'], 4),
                'unit_cost' => $line['unit_cost'],
                'total_cost' => round($quantity * $line['unit_cost'], 4),
                'notes' => $item['notes'] ?? null,
                'sort_order' => $index,
                'created_by' => auth()->id(),
            ];
        }

        if ($normalized === []) {
            throw new DomainException(__('Enter the received quantity for at least one item.'));
        }

        return $normalized;
    }

    /**
     * @param  Collection<int, GoodsReceiptItem>  $items
     */
    private function assertWithinOutstanding(PurchaseOrder $order, Collection $items, int $receiptId): void
    {
        $plan = $this->outstandingLines($order, $receiptId)->keyBy('key');

        foreach ($items as $item) {
            $key = $item->purchase_order_item_piece_id ? 'piece:'.$item->purchase_order_item_piece_id : 'item:'.$item->purchase_order_item_id;
            $line = $plan->get($key);

            if (! $line || (float) $item->quantity > $line['outstanding'] + 0.00005) {
                throw new DomainException(__(':item: received quantity (:quantity) exceeds the remaining quantity (:remaining).', [
                    'item' => $item->description,
                    'quantity' => (float) $item->quantity + 0,
                    'remaining' => ($line['outstanding'] ?? 0) + 0,
                ]));
            }
        }
    }

    /**
     * المستلم من كل قطعة/بند في سندات غير ملغاة (عدا السند الحالي). المسودات تُحسب حتى
     * لا يُستلم نفس الشيء في سندين، الا عند السؤال عن المرحّل فقط.
     *
     * @return array<string, float>
     */
    private function receivedQuantities(PurchaseOrder $order, ?int $exceptReceiptId, bool $postedOnly = false): array
    {
        return GoodsReceiptItem::query()
            ->join(config('accounting.table_prefix').'goods_receipts as receipts', 'receipts.id', '=', 'goods_receipt_id')
            ->where('receipts.purchase_order_id', $order->id)
            ->whereNull('receipts.deleted_at')
            ->when(
                $postedOnly,
                fn ($query) => $query->where('receipts.status', InventoryDocumentStatus::POSTED->value()),
                fn ($query) => $query->where('receipts.status', '!=', InventoryDocumentStatus::CANCELLED->value())
            )
            ->when($exceptReceiptId, fn ($query) => $query->where('receipts.id', '!=', $exceptReceiptId))
            ->selectRaw('purchase_order_item_id, purchase_order_item_piece_id, SUM(quantity) as quantity')
            ->groupBy('purchase_order_item_id', 'purchase_order_item_piece_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                ($row->purchase_order_item_piece_id ? 'piece:'.$row->purchase_order_item_piece_id : 'item:'.$row->purchase_order_item_id) => (float) $row->quantity,
            ])
            ->all();
    }

    /**
     * كلفة كل سطر من الطلبية بالعملة الاساسية (صافية بعد الخصومات وقبل الضريبة) لكمية
     * فوترته الحالية. سعر الطلبية اختياري، فالمصدر بالترتيب:
     *
     * 1. عرض سعر الشراء المعتمد: السعر المتفق عليه مع المورد بسعر صرف العرض.
     * 2. سعر الطلبية نفسها: مبلغ السطر، او كمية فوترته × السعر لسطر وزن بلا وزن فعلي بعد.
     * 3. كلفة مؤقتة من سعر تكلفة الصنف لسطر بلا سعر؛ فاتورة الشراء تُقفل «بضاعة لم تصل
     *    فواتيرها» بهذه القيمة وتقيّد الفرق عن سعرها الفعلي في «فروقات اسعار المشتريات».
     *
     * @return array<int, float> مفتاحها رقم سطر الطلبية
     */
    public function lineValues(PurchaseOrder $order): array
    {
        $order->loadMissing('items.product');
        $quotation = $order->purchaseQuotations()
            ->whereIn('status', [PurchaseQuotationStatus::APPROVED->value, PurchaseQuotationStatus::INVOICED->value])
            ->with('items')
            ->latest('id')
            ->first();

        if ($quotation) {
            $quoted = $quotation->items->keyBy('purchase_order_item_id');
            $linesTotal = (float) $quotation->items->sum('line_total');
            $factor = $linesTotal > 0 ? max(($linesTotal - (float) $quotation->quotation_discount) / $linesTotal, 0) : 1;
            $rate = (float) $quotation->exchange_rate ?: 1;

            return $order->items->mapWithKeys(function (PurchaseOrderItem $item) use ($quoted, $factor, $rate) {
                $line = $quoted->get($item->id);
                $value = $line
                    ? $item->invoiceQuantity() * (float) $line->unit_price * (1 - (float) $line->discount_rate / 100) * $factor * $rate
                    : $this->provisionalValue($item);

                return [$item->id => round($value, 4)];
            })->all();
        }

        $linesTotal = (float) $order->items->sum('line_total');

        $factor = $linesTotal > 0 ? max(($linesTotal - (float) $order->order_discount) / $linesTotal, 0) : 1;
        $rate = (float) $order->exchange_rate ?: 1;

        return $order->items
            ->mapWithKeys(function (PurchaseOrderItem $item) use ($factor, $rate) {
                $priced = (float) $item->line_total > 0
                    ? (float) $item->line_total
                    : $item->invoiceQuantity() * (float) $item->unit_price;

                return [$item->id => round($priced > 0 ? $priced * $factor * $rate : $this->provisionalValue($item), 4)];
            })
            ->all();
    }

    /**
     * كلفة مؤقتة لسطر لم يُسعَّر بعد: كمية الفوترة (الوزن للصنف الموزون) × متوسط تكلفة الصنف.
     */
    private function provisionalValue(PurchaseOrderItem $item): float
    {
        // كلفة المخزون من المشتريات الفعلية (المتوسط المرجّح) لا من سعر البيع، والا يُسجَّل المخزون بربح لم يتحقق.
        $unitCost = $item->product_id ? app(StockLedgerService::class)->averageCost((int) $item->product_id) : 0.0;

        return $item->invoiceQuantity() * $unitCost;
    }

    private function totals(array $items, PurchaseOrder $order, ?int $exceptReceiptId): array
    {
        return [
            'total_quantity' => round(array_sum(array_column($items, 'quantity')), 4),
            'total_weight' => round(array_sum(array_column($items, 'weight')), 4),
            'total_cost' => $this->documentValue($order, $items, $exceptReceiptId),
        ];
    }

    private function syncItems(GoodsReceipt $receipt, array $items): void
    {
        $keep = collect($items)->pluck('id')->filter()->values();
        $receipt->items()->when($keep->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $keep))->delete();

        foreach ($items as $item) {
            $id = $item['id'];
            unset($item['id']);

            if ($id && $existing = $receipt->items()->whereKey($id)->first()) {
                $existing->update($item);
            } else {
                $receipt->items()->create($item);
            }
        }
    }

    /**
     * الطلبية المؤكدة تصبح «مستلمة» حين يكتمل استلامها، وتعود «مؤكدة» اذا أُلغي سند منها.
     * الطلبية المفوترة تبقى مفوترة.
     */
    private function refreshOrderStatus(PurchaseOrder $order): void
    {
        $order->unsetRelation('items');
        $fullyReceived = $this->isFullyReceived($order);

        if ($fullyReceived && $order->status === PurchaseOrderStatus::CONFIRMED) {
            $order->update(['status' => PurchaseOrderStatus::RECEIVED->value(), 'received_at' => now()]);
        } elseif (! $fullyReceived && $order->status === PurchaseOrderStatus::RECEIVED) {
            $order->update(['status' => PurchaseOrderStatus::CONFIRMED->value(), 'received_at' => null]);
        }
    }
}
