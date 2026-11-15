<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\ProductCost;
use Modules\Accounting\Support\Quantity;
use Modules\Accounting\Support\StatusBadge;
use Modules\Accounting\Support\StockLimits;
use Modules\Accounting\Support\StockStatus;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ItemType;

/**
 * ارصدة المخزون (مثل تقرير «نوع الالمنيوم» الورقي): لكل صنف الموجود والمحجوز والقادم والمتاح
 * وحدوده (الاعلى، الادنى الاول والثاني) وموقعه وحالته وقيمته. الاستعلام هنا، والارقام من
 * StockLevelService (الصنف الرئيسي: عدد القطع من مقاطعه والكغم والقيمة من صفّه).
 */
class StockBalancesService
{
    public function __construct(private readonly StockLevelService $levels) {}

    /**
     * الاصناف السلعية مع رصيدها المجمّع (في المخزن المختار او كل المخازن) ومتوسط كلفتها.
     */
    public function query(?int $warehouseId): Builder
    {
        $products = (new Product)->getTable();
        $balances = DB::table(config('accounting.table_prefix').'stock_balances')
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as quantity, SUM(weight) as weight');

        return Product::query()
            ->with(['translations', 'mainProduct.translations', 'media'])
            ->leftJoinSub($balances, 'balances', 'balances.product_id', '=', $products.'.id')
            ->leftJoin((new ProductCost)->getTable().' as costs', 'costs.product_id', '=', $products.'.id')
            ->where(fn (Builder $query) => $query->whereNull($products.'.item_type')->orWhere($products.'.item_type', '!=', ItemType::SERVICE->value))
            ->whereNull($products.'.parent_id')
            ->select($products.'.*')
            ->selectRaw('COALESCE(balances.quantity, 0) as stock_on_hand, COALESCE(balances.weight, 0) as stock_weight, COALESCE(costs.average_cost, 0) as average_cost');
    }

    /**
     * «فقط الاصناف التي لها رصيد او حركة»: لها صف رصيد، او صنف رئيسي لبعض فروعه رصيد.
     */
    public function applyWithStock(Builder $query, ?int $warehouseId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNotNull('balances.product_id')
            ->orWhereIn((new Product)->getTable().'.id', $this->mainItemsWithStock($warehouseId)));
    }

    /**
     * نوع المنيوم: الصنف الرئيسي ومقاطعه.
     */
    public function applyMainProduct(Builder $query, int $mainProductId): Builder
    {
        return $query->where(fn (Builder $w) => $w->where('main_product_id', $mainProductId)->orWhere((new Product)->getTable().'.id', $mainProductId));
    }

    /**
     * حدود الطلب للاصناف الفعلية؛ الصنف الرئيسي يجمع فروعاً مختلفة فلا يُقاس بها.
     */
    public function applyStatus(Builder $query, string $status): Builder
    {
        return StockStatus::applyFilter($query->withoutMainItems(), $status);
    }

    /**
     * صفوف الصفحة كما يعرضها الجدول، بارقام الصفحة كلها في استعلامات قليلة.
     *
     * @param  Collection<int, Product>  $products
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $products, ?int $warehouseId, bool $canSeeCost): array
    {
        $ids = $products->pluck('id')->all();
        $levels = $this->levels->levels($ids, $warehouseId);
        $mainLevels = $this->levels->mainItemLevels($ids, $warehouseId);
        $values = $canSeeCost ? $this->levels->stockValues($ids, $warehouseId) : [];
        $locations = $warehouseId ? $this->levels->locations($ids, $warehouseId) : [];

        return $products->map(function (Product $product) use ($levels, $mainLevels, $values, $locations, $warehouseId, $canSeeCost) {
            $main = $mainLevels[$product->id] ?? null;
            $isPiece = $product->pricing_type === 'weight' && filled($product->main_product_id);
            $onHand = round((float) ($main['on_hand'] ?? $product->stock_on_hand), 4);
            $weight = round((float) ($main['weight'] ?? $product->stock_weight), 4);
            $reserved = round((float) ($levels[$product->id]['reserved'] ?? 0) + (float) ($main['reserved'] ?? 0), 4);
            $incoming = round((float) ($levels[$product->id]['incoming'] ?? 0) + (float) ($main['incoming'] ?? 0), 4);
            $available = round($onHand - $reserved, 4);
            $monitored = StockLimits::isMonitored($product);
            $status = StockStatus::for($product, $onHand);
            $places = $locations[$product->id] ?? [];

            return [
                'id' => $product->id,
                'image' => $product->image ?: null,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'detail' => $main ? __('Total of :count sub-items', ['count' => $main['sub_items']]) : $product->mainProduct?->name,
                'is_main' => (bool) $main,
                'location' => $warehouseId
                    ? (collect($places)->map(fn (array $row) => $row['code'].(count($places) > 1 ? ' ('.Quantity::format($row['quantity']).')' : ''))->join(' · ') ?: ($product->shelf ?: null))
                    : null,
                'on_hand' => Quantity::format($onHand),
                'on_hand_tone' => StatusBadge::tone($status->color()),
                'weight' => Quantity::format($weight),
                'reserved' => Quantity::format($reserved),
                'incoming' => Quantity::format($incoming),
                'available' => Quantity::format($available),
                'available_negative' => $available < 0,
                'limits' => collect([$product->max_limit, $product->min_limit_1, $product->min_limit_2])
                    ->map(fn ($limit) => (float) $limit ? Quantity::format($limit) : '–')
                    ->join(' / '),
                'max_limit' => Quantity::format($product->max_limit),
                'min_limit_1' => Quantity::format($product->min_limit_1),
                'min_limit_2' => Quantity::format($product->min_limit_2),
                // الحدود الثلاثة صفر = «غير مراقَب»: لا متابعة ولا تنبيهات مهما كان رصيده.
                'status' => $monitored ? ['label' => $status->getLabel(), 'tone' => StatusBadge::tone($status->color())] : ['label' => __('Unmonitored'), 'tone' => 'neutral'],
                'status_hint' => $monitored ? $status->description() : __('All three limits are zero, so the item has no stock alerts.'),
                'average_cost' => $canSeeCost && ! $isPiece ? Quantity::format($product->average_cost, 4) : null,
                'stock_value' => $canSeeCost ? Quantity::format(match (true) {
                    (bool) $main => round((float) $main['value'], 2),
                    $isPiece => 0.0,
                    default => round((float) ($values[$product->id] ?? 0), 2),
                }) : null,
            ];
        })->values()->all();
    }

    /**
     * الاصناف الرئيسية التي لبعض فروعها رصيد في المخزن المختار.
     */
    private function mainItemsWithStock(?int $warehouseId): QueryBuilder
    {
        return DB::table(config('accounting.table_prefix').'stock_balances as sub_balances')
            ->join((new Product)->getTable().' as sub_items', 'sub_items.id', '=', 'sub_balances.product_id')
            ->whereNotNull('sub_items.main_product_id')
            ->when($warehouseId, fn ($query) => $query->where('sub_balances.warehouse_id', $warehouseId))
            ->select('sub_items.main_product_id');
    }
}
