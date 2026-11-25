<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Collection;
use Modules\Accounting\Entities\ProductCost;
use Modules\Accounting\Entities\ProductLocation;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Support\StockStatus;
use Modules\Items\Entities\Product;

/**
 * بطاقة الصنف في المخزن (مثل شاشة النظام القديم): الارصدة والحدود والحالة والكلفة، الرصيد في
 * كل مخزن، الاماكن، الاصناف الفرعية للصنف الرئيسي، الطلبيات المفتوحة، وحركة الصنف برصيد
 * متراكم. هي الصفحة التي يفتحها رمز QR على بطاقة الرف.
 */
class ItemStockCardService
{
    public function __construct(private readonly StockLevelService $stock) {}

    /**
     * @return array<string, mixed>
     */
    public function card(Product $product, ?int $warehouseId, ?string $from, ?string $to): array
    {
        $summary = $this->summary($product, $warehouseId);

        return [
            ...$summary,
            'variations' => $summary['parentLevels'] ? $this->variations($product, $warehouseId) : collect(),
            'placements' => ProductLocation::query()
                ->with(['location', 'warehouse.translations'])
                ->where('product_id', $product->id)
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->orderByDesc('is_primary')
                ->get(),
            'locationStock' => $warehouseId ? collect($this->stock->locations([$product->id], $warehouseId)[$product->id] ?? [])->keyBy('location_id') : collect(),
            'movements' => $this->movements($product, $warehouseId, $from, $to),
        ];
    }

    /**
     * ملخص الصنف بلا حركات ولا اماكن: الارصدة والحالة والكلفة، الرصيد في كل مخزن، والطلبيات
     * المفتوحة (المحجوز في طلبيات البيع والقادم في طلبيات الشراء). تعرضه بطاقة الصنف ونافذة
     * تفاصيل الصنف في جدول الاصناف.
     *
     * @return array<string, mixed>
     */
    public function summary(Product $product, ?int $warehouseId): array
    {
        // الصنف الاب: رصيده مجموع ألوانه.
        ['levels' => $levels, 'parentLevels' => $parentLevels] = $this->stock->productLevels($product->id, $warehouseId);

        return [
            'levels' => $levels,
            'parentLevels' => $parentLevels,
            'status' => StockStatus::for($product, $levels['on_hand']),
            'cost' => ProductCost::query()->where('product_id', $product->id)->first(),
            'byWarehouse' => StockBalance::query()
                ->with('warehouse.translations')
                ->where('product_id', $product->id)
                ->groupBy('warehouse_id')
                ->selectRaw('warehouse_id, SUM(quantity) as quantity, SUM(weight) as weight')
                ->get(),
            'salesOrders' => $this->openOrders($product, $warehouseId, 'sales'),
            'purchaseOrders' => $this->openOrders($product, $warehouseId, 'purchase'),
        ];
    }

    /**
     * حركات الصنف مع الرصيد المتراكم، الاحدث اولاً. الرصيد الافتتاحي = مجموع ما قبل «من تاريخ».
     *
     * @return array{opening: float, rows: Collection<int, StockMovement>}
     */
    public function movements(Product $product, ?int $warehouseId, ?string $from, ?string $to): array
    {
        $query = StockMovement::query()
            ->with(['warehouse.translations', 'location', 'sourceable'])
            ->where('product_id', $product->id)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId));

        $opening = $from ? (float) (clone $query)->whereDate('movement_date', '<', $from)->sum('quantity') : 0.0;

        $rows = (clone $query)
            ->when($from, fn ($q) => $q->whereDate('movement_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('movement_date', '<=', $to))
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get();

        $balance = $opening;
        $rows = $rows->map(function (StockMovement $movement) use (&$balance) {
            $balance = round($balance + (float) $movement->quantity, 4);
            $movement->running_balance = $balance;
            $movement->source_url = $movement->sourceUrl();

            return $movement;
        });

        return ['opening' => $opening, 'rows' => $rows->reverse()->values()];
    }

    /**
     * طلبيات البيع المفتوحة (المحجوز) او الشراء (القادم) لهذا الصنف.
     *
     * @return Collection<int, array{reference: ?string, party: ?string, date: ?string, expected: ?string, quantity: float, order_id: int}>
     */
    public function openOrders(Product $product, ?int $warehouseId, string $type): Collection
    {
        $rows = collect($type === 'sales'
            ? $this->stock->reservedByOrder($product->id, $warehouseId)
            : $this->stock->incomingByOrder($product->id, $warehouseId));
        $model = $type === 'sales' ? SalesOrder::class : PurchaseOrder::class;
        $orders = $model::query()->with('client')->whereIn('id', $rows->pluck('order_id'))->get()->keyBy('id');

        return $rows->map(fn (array $row) => [
            'order_id' => (int) $row['order_id'],
            'reference' => $orders[$row['order_id']]?->reference_no,
            'party' => $orders[$row['order_id']]?->client?->name,
            'date' => $orders[$row['order_id']]?->order_date?->format('d/m/Y'),
            'expected' => $type === 'purchase' ? $orders[$row['order_id']]?->expected_delivery_date?->format('d/m/Y') : null,
            'quantity' => (float) $row['quantity'],
        ]);
    }

    /**
     * ألوان الصنف الاب مع رصيد كل منها.
     *
     * @return Collection<int, array{product: Product, levels: array<string, float>}>
     */
    public function variations(Product $product, ?int $warehouseId): Collection
    {
        $levels = $this->stock->variationLevels([$product->id], $warehouseId)[$product->id] ?? [];

        return Product::query()
            ->with('translations')
            ->whereIn('id', array_keys($levels))
            ->orderBy('barcode')
            ->get()
            ->map(fn (Product $variation) => ['product' => $variation, 'levels' => $levels[$variation->id]]);
    }
}
