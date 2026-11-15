<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\ProductLocation;
use Modules\Accounting\Entities\StockAdjustment;
use Modules\Accounting\Entities\StockAdjustmentItem;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Enums\StockAdjustmentType;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\Concerns\PostsInventoryJournal;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Items\Entities\Product;

/**
 * - رصيد افتتاحي: كميات موجبة بكلفتها؛ القيد من حـ/ المخزون الى حـ/ ارصدة افتتاحية.
 * - جرد: يُدخل العدد الفعلي، والفرق عن رصيد النظام لحظة الترحيل يُسجَّل زيادة او عجزاً.
 * - تسوية: كمية موجبة (زيادة) او سالبة (نقص، تلف...).
 * الزيادة والعجز يُقيَّدان على حساب «عجز وزيادة المخزون».
 */
class StockAdjustmentService
{
    use PostsInventoryJournal;

    private const RELATIONS = ['warehouse', 'items.product', 'items.location', 'journalEntry'];

    public function create(array $data): StockAdjustment
    {
        return DB::transaction(function () use ($data) {
            $header = $this->normalizeHeader($data);
            $items = $this->normalizeItems($data['items'] ?? [], $header);
            $header['reference_no'] = app(ReferenceNumberService::class)->generate('stock_adjustment', $header['adjustment_date'], ['prefix' => 'ADJ']);
            $header['status'] = InventoryDocumentStatus::DRAFT->value();
            $header['created_by'] = $data['created_by'] ?? auth()->id();

            $adjustment = StockAdjustment::create(array_merge($header, $this->totals($items)));
            $this->syncItems($adjustment, $items);

            return $adjustment->refresh()->load(self::RELATIONS);
        });
    }

    public function update(StockAdjustment $adjustment, array $data): StockAdjustment
    {
        if (! $adjustment->canBeEdited()) {
            throw new DomainException(__('Only draft documents can be updated.'));
        }

        return DB::transaction(function () use ($adjustment, $data) {
            $header = $this->normalizeHeader(array_merge($data, ['type' => $adjustment->type->value]));
            $items = $this->normalizeItems($data['items'] ?? [], $header);
            $adjustment->update(array_merge($header, $this->totals($items)));
            $this->syncItems($adjustment, $items);

            return $adjustment->refresh()->load(self::RELATIONS);
        });
    }

    public function delete(StockAdjustment $adjustment): void
    {
        if (! $adjustment->canBeEdited()) {
            throw new DomainException(__('Only draft documents can be deleted.'));
        }

        DB::transaction(function () use ($adjustment) {
            $adjustment->items()->delete();
            $adjustment->delete();
        });
    }

    public function post(StockAdjustment $adjustment): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment) {
            $adjustment = StockAdjustment::query()->with(['items.product', 'warehouse'])->lockForUpdate()->findOrFail($adjustment->id);

            if (! $adjustment->isDraft()) {
                throw new DomainException(__('Only draft documents can be posted.'));
            }

            if ($adjustment->items->isEmpty()) {
                throw new DomainException(__('The document must have at least one item.'));
            }

            $ledger = app(StockLedgerService::class);
            $layout = app(WarehouseLayoutService::class);
            $date = $adjustment->adjustment_date->toDateString();
            $increase = 0.0;
            $decrease = 0.0;

            if ($adjustment->type === StockAdjustmentType::COUNT) {
                $this->captureSystemQuantities($adjustment);
            }

            $outLines = $adjustment->items->filter(fn (StockAdjustmentItem $item) => (float) $item->quantity < 0);
            app(StockLevelService::class)->assertAvailable($adjustment->warehouse_id, $outLines->map(fn (StockAdjustmentItem $item) => [
                'product_id' => $item->product_id,
                'location_id' => $item->location_id,
                'quantity' => abs((float) $item->quantity),
            ])->values()->all());

            foreach ($adjustment->items as $item) {
                $quantity = (float) $item->quantity;

                if (abs($quantity) < 0.00005) {
                    continue;
                }

                $common = [
                    'product_id' => $item->product_id,
                    'warehouse_id' => $adjustment->warehouse_id,
                    'quantity' => abs($quantity),
                    'date' => $date,
                    'source' => $adjustment,
                    'source_line_id' => $item->id,
                    'reference_no' => $adjustment->reference_no,
                    'description' => $adjustment->type->getLabel().' '.$adjustment->reference_no,
                ];

                if ($quantity > 0) {
                    // المقطع بلا قيمة؛ القيمة على صنفه الرئيسي.
                    $unitCost = match (true) {
                        $ledger->isPiece((int) $item->product_id) => 0.0,
                        (float) $item->unit_cost > 0 => (float) $item->unit_cost,
                        default => $this->fallbackCost($item->product),
                    };
                    $locationId = $item->location_id ?: $layout->primaryLocationId($item->product_id, $adjustment->warehouse_id);

                    $ledger->receive(array_merge($common, [
                        'location_id' => $locationId,
                        'weight' => abs((float) $item->weight),
                        'unit_cost' => $unitCost,
                        'type' => $adjustment->type === StockAdjustmentType::OPENING ? StockMovementType::OPENING : StockMovementType::ADJUSTMENT_IN,
                    ]));

                    if ($item->location_id) {
                        $layout->rememberLocation($item->product_id, $adjustment->warehouse_id, (int) $item->location_id);
                    }

                    $cost = round(abs($quantity) * $unitCost, 4);
                    $increase += $cost;
                    $item->update(['unit_cost' => $unitCost, 'total_cost' => $cost]);

                    continue;
                }

                $movements = $ledger->issue(array_merge($common, [
                    'location_id' => $item->location_id,
                    'weight' => null,
                    'type' => StockMovementType::ADJUSTMENT_OUT,
                ]));

                $cost = round((float) $movements->sum(fn (StockMovement $movement) => -(float) $movement->total_cost), 4);
                $decrease += $cost;
                $item->update([
                    'unit_cost' => round($cost / abs($quantity), 6),
                    'total_cost' => -$cost,
                    'weight' => round((float) $movements->sum('weight'), 4),
                ]);
            }

            $accounts = app(InventoryAccounts::class);
            $inventoryAccount = $accounts->warehouseAccountId($adjustment->warehouse);
            $offsetAccount = $accounts->mapped($adjustment->type === StockAdjustmentType::OPENING ? InventoryAccounts::OPENING_KEY : InventoryAccounts::SHRINKAGE_KEY);
            $increase = round($increase, 4);
            $decrease = round($decrease, 4);
            $description = $adjustment->type->getLabel().' '.$adjustment->reference_no;

            $entry = $this->postInventoryJournal($adjustment, 'StockAdjustment', $date, $description, [
                ['account_id' => $inventoryAccount, 'debit' => $increase, 'credit' => $decrease],
                ['account_id' => $offsetAccount, 'debit' => $decrease, 'credit' => $increase],
            ]);

            $adjustment->update([
                'status' => InventoryDocumentStatus::POSTED->value(),
                'total_cost' => round($increase - $decrease, 4),
                'total_quantity' => round((float) $adjustment->items()->sum('quantity'), 4),
                'journal_entry_id' => $entry?->id,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            return $adjustment->refresh()->load(self::RELATIONS);
        });
    }

    public function cancel(StockAdjustment $adjustment): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment) {
            $adjustment = StockAdjustment::query()->with('journalEntry')->lockForUpdate()->findOrFail($adjustment->id);

            if (! $adjustment->isPosted()) {
                throw new DomainException(__('Only posted documents can be cancelled.'));
            }

            app(StockLedgerService::class)->reverse($adjustment, now()->toDateString());
            $this->voidInventoryJournal($adjustment, __('Cancellation of').' '.$adjustment->reference_no);

            $adjustment->update([
                'status' => InventoryDocumentStatus::CANCELLED->value(),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
            ]);

            return $adjustment->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * سطور ورقة الجرد: اصناف المخزن (او المختارة) مع رصيد النظام الحالي.
     *
     * @param  array<int, int>|null  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function countSheet(Warehouse $warehouse, ?array $productIds = null, ?int $mainProductId = null): array
    {
        $levels = app(StockLevelService::class);
        $ids = $productIds ?? ProductLocation::query()
            ->where('warehouse_id', $warehouse->id)
            ->pluck('product_id')
            ->merge(StockBalance::query()->where('warehouse_id', $warehouse->id)->where('quantity', '!=', 0)->pluck('product_id'))
            ->unique()
            ->values()
            ->all();

        $products = Product::query()
            ->with('translations')
            ->whereIn('id', $ids)
            ->when($mainProductId, fn ($query) => $query->where(fn ($q) => $q->where('main_product_id', $mainProductId)->orWhereKey($mainProductId)))
            ->orderBy('barcode')
            ->get();
        $onHand = $levels->levels($products->pluck('id')->all(), $warehouse->id);

        return $products->map(fn (Product $product) => [
            'product_id' => $product->id,
            'barcode' => $product->barcode,
            'description' => $product->name,
            'system_quantity' => $onHand[$product->id]['on_hand'] ?? 0,
            'counted_quantity' => null,
            'location_id' => null,
        ])->all();
    }

    /**
     * كشف الجرد في الشاشة: اصناف المخزن، او نوع المنيوم واحد (الصنف الرئيسي يُعدّ بالكغم
     * ومقاطعه بالعدد)، دون الاصناف الموجودة في المستند.
     *
     * @param  array<int, int|string>  $exceptProductIds
     * @return list<array<string, mixed>>
     */
    public function countSheetLines(Warehouse $warehouse, ?int $mainProductId = null, array $exceptProductIds = []): array
    {
        $ids = $mainProductId
            ? Product::query()->where('main_product_id', $mainProductId)->pluck('id')->push($mainProductId)->all()
            : null;
        $except = array_map('intval', $exceptProductIds);

        return array_values(array_map(
            fn (array $row) => array_merge($row, ['quantity' => null, 'unit_cost' => null]),
            array_filter($this->countSheet($warehouse, $ids), fn (array $row) => ! in_array((int) $row['product_id'], $except, true)),
        ));
    }

    /**
     * سطر صنف يُضاف في الشاشة: في الجرد يبدأ العدد بواحد (مسح الباركود)، وفي غيره الكمية واحد؛
     * ومعه رصيد النظام في المخزن.
     *
     * @return array<string, mixed>
     */
    public function newLine(Product $product, StockAdjustmentType $type, ?int $warehouseId): array
    {
        $isCount = $type === StockAdjustmentType::COUNT;

        return [
            'product_id' => $product->id,
            'barcode' => $product->barcode,
            'description' => $product->name,
            'location_id' => null,
            'quantity' => $isCount ? null : 1,
            'counted_quantity' => $isCount ? 1 : null,
            'system_quantity' => $warehouseId ? app(StockLedgerService::class)->onHand($product->id, $warehouseId) : 0,
            'unit_cost' => null,
        ];
    }

    private function captureSystemQuantities(StockAdjustment $adjustment): void
    {
        $ledger = app(StockLedgerService::class);

        foreach ($adjustment->items as $item) {
            $system = $ledger->onHand($item->product_id, $adjustment->warehouse_id, $item->location_id ?: null);
            $difference = round((float) $item->counted_quantity - $system, 4);

            $item->update([
                'system_quantity' => $system,
                'quantity' => $difference,
                'weight' => $this->stockWeight($item->product, $difference),
            ]);
        }

        $adjustment->load('items.product');
    }

    private function fallbackCost(?Product $product): float
    {
        if (! $product) {
            return 0;
        }

        // المقطع بلا قيمة؛ كلفة الكغم على صنفه الرئيسي الذي تتحرك كمياته بالكغم.
        if (app(StockLedgerService::class)->isPiece($product->id)) {
            return 0;
        }

        $average = app(StockLedgerService::class)->averageCost($product->id);

        return $average > 0 ? $average : round((float) $product->cost_price, 6);
    }

    private function normalizeHeader(array $data): array
    {
        $type = StockAdjustmentType::tryFrom((string) ($data['type'] ?? '')) ?? throw new DomainException(__('Invalid adjustment type.'));
        $warehouseId = (int) ($data['warehouse_id'] ?? 0);

        if (! $warehouseId) {
            throw new DomainException(__('Please select a warehouse.'));
        }

        return [
            'type' => $type->value,
            'warehouse_id' => $warehouseId,
            'branch_id' => $data['branch_id'] ?? Warehouse::query()->whereKey($warehouseId)->value('branch_id'),
            'adjustment_date' => $data['adjustment_date'] ?? now()->toDateString(),
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function normalizeItems(array $items, array $header): array
    {
        $type = StockAdjustmentType::from($header['type']);
        $products = Product::query()->with('translations')->whereIn('id', collect($items)->pluck('product_id')->filter())->get()->keyBy('id');
        $ledger = app(StockLedgerService::class);
        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            $product = $products->get((int) ($item['product_id'] ?? 0));

            if (! $product) {
                continue;
            }

            if ($product->isService()) {
                throw new DomainException(__(':item is a service and has no stock.', ['item' => $product->name]));
            }

            $locationId = filled($item['location_id'] ?? null) ? (int) $item['location_id'] : null;
            $counted = null;
            $system = null;

            if ($type === StockAdjustmentType::COUNT) {
                if (($item['counted_quantity'] ?? '') === '' || $item['counted_quantity'] === null) {
                    continue;
                }

                $counted = $this->decimal($item['counted_quantity']);

                if ($counted < 0) {
                    throw new DomainException(__('Counted quantity cannot be negative.'));
                }

                $system = $ledger->onHand($product->id, $header['warehouse_id'], $locationId);
                $quantity = round($counted - $system, 4);
            } else {
                $quantity = $this->decimal($item['quantity'] ?? 0);

                if ($type === StockAdjustmentType::OPENING && $quantity < 0) {
                    throw new DomainException(__('Opening balance quantities must be positive.'));
                }

                if ($quantity == 0) {
                    continue;
                }
            }

            $unitCost = $this->decimal($item['unit_cost'] ?? 0, 0, 6);

            if ($unitCost < 0) {
                throw new DomainException(__('Unit cost cannot be negative.'));
            }

            if ($unitCost == 0 || $ledger->isPiece($product->id)) {
                $unitCost = $this->fallbackCost($product);
            }

            $normalized[] = [
                'id' => $item['id'] ?? null,
                'product_id' => $product->id,
                'location_id' => $locationId,
                'system_quantity' => $system,
                'counted_quantity' => $counted,
                'barcode' => $product->barcode,
                'description' => $product->name,
                'quantity' => $quantity,
                'piece_length' => (float) $product->length,
                'weight' => $this->stockWeight($product, $quantity),
                'unit_cost' => $unitCost,
                'total_cost' => round($quantity * $unitCost, 4),
                'notes' => $item['notes'] ?? null,
                'sort_order' => $index,
                'created_by' => auth()->id(),
            ];
        }

        if ($normalized === []) {
            throw new DomainException(__('The document must have at least one item.'));
        }

        return $normalized;
    }

    private function totals(array $items): array
    {
        return [
            'total_quantity' => round(array_sum(array_column($items, 'quantity')), 4),
            'total_weight' => round(array_sum(array_column($items, 'weight')), 4),
            'total_cost' => round(array_sum(array_column($items, 'total_cost')), 4),
        ];
    }

    private function syncItems(StockAdjustment $adjustment, array $items): void
    {
        $adjustment->items()->delete();

        foreach ($items as $item) {
            unset($item['id']);
            $adjustment->items()->create($item);
        }
    }
}
