<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\DeliveryNoteItem;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Entities\WarehouseLocation;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Enums\PurchaseOrderDeliveryType;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\Concerns\PostsInventoryJournal;
use Modules\Accounting\Support\MainItemShare;
use Modules\Accounting\Support\StockKey;
use Modules\Core\Services\ReferenceNumberService;

/**
 * سند التسليم: يُخرج بضاعة طلبية البيع من المخزن الى الزبون.
 *
 * - يقبل التسليم الجزئي؛ المتبقي = المطلوب − المسلَّم في سندات اخرى غير ملغاة.
 * - الصنف الموزون يُسلَّم بقطعه بالعدد بلا قيمة، ومعها حركة صنفها الرئيسي: الكغم = الوزن
 *   الفعلي في الطلبية ان وُجد والا الوزن المحسوب، بنسبة ما سلّمه السند من القطع.
 * - الخروج بتكلفة FIFO، ويُمنع ان لم تكفِ الكمية (قطع بالعدد، والرئيسي بالكغم).
 * - طلبية البيع التي يشحنها المورد مباشرة للزبون لا تحتاج سند تسليم.
 */
class DeliveryNoteService
{
    use PostsInventoryJournal;

    private const RELATIONS = ['salesOrder', 'client', 'warehouse', 'items.product', 'items.location', 'journalEntry'];

    public const DELIVERABLE_STATUSES = [SalesOrderStatus::CONFIRMED, SalesOrderStatus::PREPARING, SalesOrderStatus::INVOICED];

    public function create(array $data): DeliveryNote
    {
        return DB::transaction(function () use ($data) {
            $order = $this->deliverableOrder((int) ($data['sales_order_id'] ?? 0));
            $header = $this->normalizeHeader($data, $order);
            $items = $this->normalizeItems($order, $data['items'] ?? [], null);

            $header['reference_no'] = app(ReferenceNumberService::class)->generate('delivery_note', $header['delivery_date'], ['prefix' => 'DN']);
            $header['status'] = InventoryDocumentStatus::DRAFT->value();
            $header['created_by'] = $data['created_by'] ?? auth()->id();

            $note = DeliveryNote::create(array_merge($header, $this->totals($items, $order, null)));
            $this->syncItems($note, $items);

            return $note->refresh()->load(self::RELATIONS);
        });
    }

    public function update(DeliveryNote $note, array $data): DeliveryNote
    {
        if (! $note->canBeEdited()) {
            throw new DomainException(__('Only draft documents can be updated.'));
        }

        return DB::transaction(function () use ($note, $data) {
            $order = $this->deliverableOrder((int) $note->sales_order_id);
            $header = $this->normalizeHeader($data, $order);
            $items = $this->normalizeItems($order, $data['items'] ?? [], $note->id);

            $note->update(array_merge($header, $this->totals($items, $order, $note->id)));
            $this->syncItems($note, $items);

            return $note->refresh()->load(self::RELATIONS);
        });
    }

    public function delete(DeliveryNote $note): void
    {
        if (! $note->canBeEdited()) {
            throw new DomainException(__('Only draft documents can be deleted.'));
        }

        DB::transaction(function () use ($note) {
            $note->items()->delete();
            $note->delete();
        });
    }

    /**
     * @param  array<int, float>  $invoicedKg  سطر الطلبية => الكغم المفوتر، لسند الفاتورة التلقائي
     */
    public function post(DeliveryNote $note, array $invoicedKg = []): DeliveryNote
    {
        return DB::transaction(function () use ($note, $invoicedKg) {
            $note = DeliveryNote::query()->with(['items.product', 'warehouse'])->lockForUpdate()->findOrFail($note->id);

            if (! $note->isDraft()) {
                throw new DomainException(__('Only draft documents can be posted.'));
            }

            if ($note->items->isEmpty()) {
                throw new DomainException(__('The document must have at least one item.'));
            }

            $order = $this->deliverableOrder((int) $note->sales_order_id, true);
            $this->assertWithinOutstanding($order, $note->items, $note->id);
            $mainRows = array_values(array_filter($this->mainRows($order, $note->items, $note->id, true, $invoicedKg), fn (array $row) => $row['kg'] > 0));
            app(StockLevelService::class)->assertAvailable($note->warehouse_id, array_merge(
                $note->items->map(fn (DeliveryNoteItem $item) => [
                    'product_id' => $item->product_id,
                    'location_id' => $item->location_id,
                    'quantity' => (float) $item->quantity,
                ])->all(),
                array_map(fn (array $row) => [...$row['key']->attributes(), 'location_id' => null, 'quantity' => $row['kg']], $mainRows)
            ));

            $ledger = app(StockLedgerService::class);
            $locationCodes = WarehouseLocation::query()->where('warehouse_id', $note->warehouse_id)->pluck('code', 'id');

            foreach ($note->items as $item) {
                $movements = $ledger->issue([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $note->warehouse_id,
                    'location_id' => $item->location_id,
                    'quantity' => (float) $item->quantity,
                    'weight' => (float) $item->weight > 0 ? (float) $item->weight : null,
                    'type' => StockMovementType::DELIVERY,
                    'date' => $note->delivery_date->toDateString(),
                    'source' => $note,
                    'source_line_id' => $item->id,
                    'reference_no' => $note->reference_no,
                    'description' => __('Delivery Note').' '.$note->reference_no,
                ]);

                $totalCost = round((float) $movements->sum(fn (StockMovement $movement) => -(float) $movement->total_cost), 4);

                $item->update([
                    'unit_cost' => (float) $item->quantity > 0 ? round($totalCost / (float) $item->quantity, 6) : 0,
                    'total_cost' => $totalCost,
                    'picked_locations' => $movements->map(fn (StockMovement $movement) => [
                        'location_id' => $movement->location_id ?: null,
                        'code' => $movement->location_id ? ($locationCodes[$movement->location_id] ?? null) : null,
                        'quantity' => -(float) $movement->quantity,
                    ])->values()->all(),
                ]);
            }

            // حركة الصنف الرئيسي لكل سطر وزن: الكغم بتكلفته، والقطع بلا قيمة.
            $mainCost = 0.0;

            foreach ($mainRows as $row) {
                $movements = $ledger->issue([
                    ...$row['key']->attributes(),
                    'warehouse_id' => $note->warehouse_id,
                    'quantity' => $row['kg'],
                    'weight' => $row['kg'],
                    'type' => StockMovementType::DELIVERY,
                    'date' => $note->delivery_date->toDateString(),
                    'source' => $note,
                    'source_line_id' => $row['line_id'],
                    'reference_no' => $note->reference_no,
                    'description' => __('Delivery Note').' '.$note->reference_no,
                ]);

                $mainCost += -(float) $movements->sum('total_cost');
            }

            $total = round((float) $note->items()->sum('total_cost') + $mainCost, 4);
            $accounts = app(InventoryAccounts::class);
            $description = __('Delivery Note').' '.$note->reference_no.' - '.$order->reference_no;
            $entry = $this->postInventoryJournal($note, 'DeliveryNote', $note->delivery_date->toDateString(), $description, [
                ['account_id' => $accounts->mapped(InventoryAccounts::COGS_KEY), 'debit' => $total, 'credit' => 0],
                ['account_id' => $accounts->warehouseAccountId($note->warehouse), 'debit' => 0, 'credit' => $total],
            ]);

            $note->update([
                'status' => InventoryDocumentStatus::POSTED->value(),
                'total_cost' => $total,
                'journal_entry_id' => $entry?->id,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            return $note->refresh()->load(self::RELATIONS);
        });
    }

    public function cancel(DeliveryNote $note): DeliveryNote
    {
        return DB::transaction(function () use ($note) {
            $note = DeliveryNote::query()->with('journalEntry')->lockForUpdate()->findOrFail($note->id);

            if (! $note->isPosted()) {
                throw new DomainException(__('Only posted documents can be cancelled.'));
            }

            app(StockLedgerService::class)->reverse($note, now()->toDateString());
            $this->voidInventoryJournal($note, __('Cancellation of').' '.$note->reference_no);

            $note->update([
                'status' => InventoryDocumentStatus::CANCELLED->value(),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
            ]);

            return $note->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * يسلّم كل المتبقي من الطلبية بسند واحد مرحّل (عند ترحيل فاتورة المبيعات)؛ يُمنع ان
     * لم تكفِ الكمية. يعيد null اذا لم يبقَ شيء؛ سندات المسودة تبقى لمرحّلها.
     */
    public function deliverOutstanding(SalesOrder $order, array $header, array $invoicedKg = []): ?DeliveryNote
    {
        $items = $this->outstandingLines($order)
            ->filter(fn (array $line) => $line['outstanding'] > 0)
            ->map(fn (array $line) => [
                'sales_order_item_id' => $line['sales_order_item_id'],
                'sales_order_item_piece_id' => $line['sales_order_item_piece_id'],
                'quantity' => $line['outstanding'],
            ])
            ->values()
            ->all();

        if ($items === []) {
            return null;
        }

        $note = $this->create(array_merge($header, ['sales_order_id' => $order->id, 'items' => $items]));

        if (filled($header['sales_invoice_id'] ?? null)) {
            $note->update(['sales_invoice_id' => (int) $header['sales_invoice_id']]);
        }

        return $this->post($note, $invoicedKg);
    }

    /**
     * الغاء سند تسليم انشأته فاتورة مبيعات تُلغى الآن: تعود البضاعة ويُعكس القيد بتاريخ الالغاء.
     */
    public function cancelForInvoice(DeliveryNote $note, string $date): DeliveryNote
    {
        return DB::transaction(function () use ($note, $date) {
            $note = DeliveryNote::query()->with('journalEntry')->lockForUpdate()->findOrFail($note->id);

            if (! $note->isPosted()) {
                return $note;
            }

            app(StockLedgerService::class)->reverse($note, $date);
            $this->voidInventoryJournal($note, __('Cancellation of').' '.$note->reference_no, $date);

            $note->update([
                'status' => InventoryDocumentStatus::CANCELLED->value(),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
            ]);

            return $note->refresh();
        });
    }

    /**
     * سطور الطلبية القابلة للتسليم مع المطلوب والمسلَّم والمتبقي.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function outstandingLines(SalesOrder $order, ?int $exceptNoteId = null, bool $postedOnly = false): Collection
    {
        $order->loadMissing(['items.product.translations', 'items.pieces.product.translations']);
        $delivered = $this->deliveredQuantities($order, $exceptNoteId, $postedOnly);
        $lines = collect();

        foreach ($order->items as $item) {
            $isGroup = $item->isPriceGroupLine();

            if (! $isGroup && (! $item->product || $item->product->isService() || ($item->line_type ?? 'goods') === 'service')) {
                continue;
            }

            if ($item->pieces->isNotEmpty()) {
                // كما في اتقان: القطع بوزنها المحسوب؛ الكيلو الفعلي على مجموعة السعر (او صنف السطر) في mainRows.
                $weights = $item->pieces->mapWithKeys(fn ($piece) => [$piece->id => (float) $piece->total_weight])->all();

                foreach ($item->pieces as $piece) {
                    $count = (float) $piece->piece_count;

                    if ($count <= 0 || ! $piece->product_id) {
                        continue;
                    }

                    $deliveredQuantity = (float) ($delivered['piece:'.$piece->id] ?? 0);

                    $lines->push([
                        'key' => 'piece:'.$piece->id,
                        'sales_order_item_id' => $item->id,
                        'sales_order_item_piece_id' => $piece->id,
                        'product_id' => $piece->product_id,
                        'barcode' => $piece->barcode ?: $piece->product?->barcode,
                        'description' => $piece->description ?: $piece->product?->name,
                        'group' => $item->description ?: $item->product?->name,
                        'piece_length' => (float) $piece->piece_length,
                        'ordered' => $count,
                        'delivered' => $deliveredQuantity,
                        'outstanding' => max(round($count - $deliveredQuantity, 4), 0),
                        'weight_per_unit' => round($weights[$piece->id] / $count, 4),
                    ]);
                }

                continue;
            }

            // سطر مجموعة السعر بلا قطع لا شيء فيه يُسلَّم.
            if ($isGroup) {
                continue;
            }

            // صنف رئيسي بلا قطع يُسلَّم بالكغم.
            $isMainLine = app(StockLedgerService::class)->storesByWeight($item->product);
            $quantity = $isMainLine ? $item->invoiceQuantity() : (float) $item->quantity;

            if ($quantity <= 0) {
                continue;
            }

            $deliveredQuantity = (float) ($delivered['item:'.$item->id] ?? 0);

            $lines->push([
                'key' => 'item:'.$item->id,
                'sales_order_item_id' => $item->id,
                'sales_order_item_piece_id' => null,
                'product_id' => $item->product_id,
                'barcode' => $item->barcode ?: $item->product->barcode,
                'description' => $item->description ?: $item->product->name,
                'group' => null,
                'piece_length' => (float) $item->product->length,
                'ordered' => $quantity,
                'delivered' => $deliveredQuantity,
                'outstanding' => max(round($quantity - $deliveredQuantity, 4), 0),
                'weight_per_unit' => $isMainLine ? 1.0 : ((float) $item->total_weight > 0 ? round((float) $item->total_weight / $quantity, 4) : $this->weightPerUnit($item->product)),
            ]);
        }

        return $lines;
    }

    /**
     * طلبية البيع التي ترتبط بها طلبية شراء «شحن مباشر للزبون» يسلّمها المورد.
     */
    public function isDirectShipment(SalesOrder|int $order): bool
    {
        $order = $order instanceof SalesOrder ? $order : SalesOrder::query()->find($order);

        if (! $order) {
            return false;
        }

        // طلبية البيع نفسها قد تُعلَّم «شحن مباشر من المورد» قبل انشاء طلبية الشراء.
        if (($order->getAttributes()['fulfillment_type'] ?? null) === 'direct') {
            return true;
        }

        return PurchaseOrder::query()
            ->where('direct_sales_order_id', $order->id)
            ->where('delivery_type', PurchaseOrderDeliveryType::DIRECT->value)
            ->where('status', '!=', PurchaseOrderStatus::CANCELLED->value())
            ->exists();
    }

    public function isFullyDelivered(SalesOrder $order): bool
    {
        $lines = $this->outstandingLines($order, null, true);

        return $lines->isNotEmpty() && $lines->every(fn (array $line) => $line['outstanding'] <= 0);
    }

    /**
     * سطور شاشة سند التسليم = سطور الطلبية المتبقية، مع كمية هذا السند وموقعه والمواقع التي
     * سُحب منها ان كان محفوظاً. المسودة تعرض ما بقي قابلاً للتسليم، والمرحّل/الملغى سطوره فقط.
     *
     * @return list<array<string, mixed>>
     */
    public function formLines(SalesOrder $order, ?DeliveryNote $note = null): array
    {
        $editable = ! $note || $note->canBeEdited();
        $saved = $note
            ? $note->loadMissing('items')->items->keyBy(fn (DeliveryNoteItem $item) => $item->sales_order_item_piece_id ? 'piece:'.$item->sales_order_item_piece_id : 'item:'.$item->sales_order_item_id)
            : collect();

        return $this->outstandingLines($order, $note?->id, $note && ! $note->isDraft())
            ->filter(fn (array $line) => $saved->has($line['key']) || ($editable && $line['outstanding'] > 0))
            ->map(function (array $line) use ($saved) {
                $item = $saved->get($line['key']);

                return array_merge($line, [
                    'id' => $item?->id,
                    'quantity' => $item ? (float) $item->quantity : null,
                    'location_id' => $item?->location_id,
                    'picked' => $item?->picked_locations ?? [],
                    'notes' => $item?->notes,
                    'unit_cost' => $item ? (float) $item->unit_cost : 0.0,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * «تعبئة من الرصيد المتوفر»: لكل سطر ما يغطيه رصيد الصنف في المخزن بعد ما أخذته السطور
     * قبله، ولا يتجاوز المتبقي. النتيجة كمية لكل مفتاح سطر (null = لا شيء متوفر).
     *
     * @param  array<int, array{key: string, product_id: int|string, outstanding: float|int|string}>  $lines
     * @return array<string, float|null>
     */
    public function fillFromAvailable(array $lines, int $warehouseId): array
    {
        $levels = app(StockLevelService::class)->levels(array_column($lines, 'product_id'), $warehouseId);
        $remaining = array_map(fn (array $level) => $level['on_hand'], $levels);
        $quantities = [];

        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $available = max($remaining[$productId] ?? 0, 0);
            $quantity = min((float) $line['outstanding'], $available);
            $quantities[(string) $line['key']] = $quantity > 0 ? round($quantity, 4) : null;
            $remaining[$productId] = $available - $quantity;
        }

        return $quantities;
    }

    private function deliverableOrder(int $orderId, bool $lock = false): SalesOrder
    {
        $query = SalesOrder::query()->with(['items.product', 'items.pieces.product'])->whereKey($orderId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $order = $query->first();

        if (! $order) {
            throw new DomainException(__('Sales order not found.'));
        }

        if (! in_array($order->status, self::DELIVERABLE_STATUSES, true)) {
            throw new DomainException(__('Only confirmed sales orders can be delivered.'));
        }

        if ($this->isDirectShipment($order)) {
            throw new DomainException(__('This sales order is shipped directly by the supplier and does not leave the warehouse.'));
        }

        return $order;
    }

    private function normalizeHeader(array $data, SalesOrder $order): array
    {
        $warehouseId = (int) ($data['warehouse_id'] ?? $order->warehouse_id);

        if (! $warehouseId) {
            throw new DomainException(__('Please select a warehouse.'));
        }

        return [
            'sales_order_id' => $order->id,
            'client_id' => $order->client_id,
            'branch_id' => $data['branch_id'] ?? $order->branch_id,
            'warehouse_id' => $warehouseId,
            'delivery_date' => $data['delivery_date'] ?? now()->toDateString(),
            'driver_name' => $data['driver_name'] ?? null,
            'vehicle_no' => $data['vehicle_no'] ?? null,
            'receiver_name' => $data['receiver_name'] ?? null,
            'delivery_address' => $data['delivery_address'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function normalizeItems(SalesOrder $order, array $items, ?int $exceptNoteId): array
    {
        $plan = $this->outstandingLines($order, $exceptNoteId)->keyBy('key');
        $averageCosts = app(StockLedgerService::class);
        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            $quantity = $this->decimal($item['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $key = filled($item['sales_order_item_piece_id'] ?? null)
                ? 'piece:'.$item['sales_order_item_piece_id']
                : 'item:'.($item['sales_order_item_id'] ?? 0);
            $line = $plan->get($key);

            if (! $line) {
                throw new DomainException(__('The item does not belong to the sales order.'));
            }

            if ($quantity > $line['outstanding'] + 0.00005) {
                throw new DomainException(__(':item: delivered quantity (:quantity) exceeds the remaining quantity (:remaining).', [
                    'item' => $line['description'],
                    'quantity' => $quantity + 0,
                    'remaining' => $line['outstanding'] + 0,
                ]));
            }

            $unitCost = $averageCosts->averageCost((int) $line['product_id']);

            $normalized[] = [
                'id' => $item['id'] ?? null,
                'sales_order_item_id' => $line['sales_order_item_id'],
                'sales_order_item_piece_id' => $line['sales_order_item_piece_id'],
                'product_id' => $line['product_id'],
                'location_id' => filled($item['location_id'] ?? null) ? (int) $item['location_id'] : null,
                'barcode' => $line['barcode'],
                'description' => $line['description'],
                'quantity' => $quantity,
                'piece_length' => $line['piece_length'],
                'weight' => round($quantity * $line['weight_per_unit'], 4),
                'unit_cost' => $unitCost,
                'total_cost' => round($quantity * $unitCost, 4),
                'notes' => $item['notes'] ?? null,
                'sort_order' => $index,
                'created_by' => auth()->id(),
            ];
        }

        if ($normalized === []) {
            throw new DomainException(__('Enter the delivered quantity for at least one item.'));
        }

        return $normalized;
    }

    /**
     * @param  Collection<int, DeliveryNoteItem>  $items
     */
    private function assertWithinOutstanding(SalesOrder $order, Collection $items, int $noteId): void
    {
        $plan = $this->outstandingLines($order, $noteId)->keyBy('key');

        foreach ($items as $item) {
            $key = $item->sales_order_item_piece_id ? 'piece:'.$item->sales_order_item_piece_id : 'item:'.$item->sales_order_item_id;
            $line = $plan->get($key);

            if (! $line || (float) $item->quantity > $line['outstanding'] + 0.00005) {
                throw new DomainException(__(':item: delivered quantity (:quantity) exceeds the remaining quantity (:remaining).', [
                    'item' => $item->description,
                    'quantity' => (float) $item->quantity + 0,
                    'remaining' => ($line['outstanding'] ?? 0) + 0,
                ]));
            }
        }
    }

    /**
     * @return array<string, float>
     */
    private function deliveredQuantities(SalesOrder $order, ?int $exceptNoteId, bool $postedOnly): array
    {
        return DeliveryNoteItem::query()
            ->join(config('accounting.table_prefix').'delivery_notes as notes', 'notes.id', '=', 'delivery_note_id')
            ->where('notes.sales_order_id', $order->id)
            ->whereNull('notes.deleted_at')
            ->when(
                $postedOnly,
                fn ($query) => $query->where('notes.status', InventoryDocumentStatus::POSTED->value()),
                fn ($query) => $query->where('notes.status', '!=', InventoryDocumentStatus::CANCELLED->value())
            )
            ->when($exceptNoteId, fn ($query) => $query->where('notes.id', '!=', $exceptNoteId))
            ->selectRaw('sales_order_item_id, sales_order_item_piece_id, SUM(quantity) as quantity')
            ->groupBy('sales_order_item_id', 'sales_order_item_piece_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                ($row->sales_order_item_piece_id ? 'piece:'.$row->sales_order_item_piece_id : 'item:'.$row->sales_order_item_id) => (float) $row->quantity,
            ])
            ->all();
    }

    private function totals(array $items, SalesOrder $order, ?int $exceptNoteId): array
    {
        // تقدير حتى الترحيل: الترحيل يضع كلفة الخروج الفعلية.
        $main = collect($this->mainRows($order, $items, $exceptNoteId, false))
            ->sum(fn (array $row) => round($row['kg'] * ($row['key']->isGroup() ? app(StockLedgerService::class)->groupAverageCost($row['key']->id) : app(StockLedgerService::class)->averageCost($row['key']->id)), 4));

        return [
            'total_quantity' => round(array_sum(array_column($items, 'quantity')), 4),
            'total_weight' => round(array_sum(array_column($items, 'weight')), 4),
            'total_cost' => round(array_sum(array_column($items, 'total_cost')) + $main, 4),
        ];
    }

    /**
     * صف الصنف الرئيسي لكل سطر وزن يسلّمه السند: الكغم (الوزن الفعلي في الطلبية او المفوتر، والا
     * الوزن المحسوب) بنصيب ما سلّمه السند من قطع السطر (MainItemShare).
     *
     * @param  iterable<int, mixed>  $rows  سطور السند (قطعة/كمية/وزن)
     * @param  array<int, float>  $invoicedKg  سطر الطلبية => الكغم المفوتر
     * @return array<int, array{product_id:int, kg:float, line_id:?int}>
     */
    private function mainRows(SalesOrder $order, iterable $rows, ?int $exceptNoteId, bool $postedOnly, array $invoicedKg = []): array
    {
        $grouped = collect($rows)
            ->filter(fn ($row) => filled(data_get($row, 'sales_order_item_piece_id')))
            ->groupBy(fn ($row) => (int) data_get($row, 'sales_order_item_id'));

        if ($grouped->isEmpty()) {
            return [];
        }

        $order->loadMissing(['items.product', 'items.pieces']);
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
            $prior = (float) DeliveryNoteItem::query()
                ->join(config('accounting.table_prefix').'delivery_notes as notes', 'notes.id', '=', 'delivery_note_id')
                ->where('sales_order_item_id', $itemId)
                ->whereNotNull('sales_order_item_piece_id')
                ->whereNull('notes.deleted_at')
                ->when(
                    $postedOnly,
                    fn ($query) => $query->where('notes.status', InventoryDocumentStatus::POSTED->value()),
                    fn ($query) => $query->where('notes.status', '!=', InventoryDocumentStatus::CANCELLED->value())
                )
                ->when($exceptNoteId, fn ($query) => $query->where('notes.id', '!=', $exceptNoteId))
                ->sum($column);
            $kg = array_key_exists($itemId, $invoicedKg)
                ? (float) $invoicedKg[$itemId]
                : ((float) $item->actual_weight > 0 ? (float) $item->actual_weight : $calculated);

            $result[] = [
                'key' => $item->isPriceGroupLine() ? StockKey::group((int) $item->category_finish_id) : StockKey::product((int) $item->product_id),
                'kg' => MainItemShare::slice($kg, $whole, $prior, $current),
                'line_id' => data_get($itemRows->first(), 'id') ? (int) data_get($itemRows->first(), 'id') : null,
            ];
        }

        return $result;
    }

    private function syncItems(DeliveryNote $note, array $items): void
    {
        $keep = collect($items)->pluck('id')->filter()->values();
        $note->items()->when($keep->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $keep))->delete();

        foreach ($items as $item) {
            $id = $item['id'];
            unset($item['id']);

            if ($id && $existing = $note->items()->whereKey($id)->first()) {
                $existing->update($item);
            } else {
                $note->items()->create($item);
            }
        }
    }
}
