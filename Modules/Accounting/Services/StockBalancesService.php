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
 * ارصدة المخزون: لكل صنف الموجود والمحجوز والقادم والمتاح وحدوده (الاعلى، الادنى الاول والثاني)
 * وموقعه وحالته وقيمته. الاستعلام هنا، والارقام من StockLevelService: الصنف الاب مجموع ألوانه،
 * وبفلتر (التصنيف × اللون) تُعرض ألوان المجموعة وسطر مجموعها.
 */
class StockBalancesService
{
    public function __construct(private readonly StockLevelService $levels) {}

    /**
     * الاصناف السلعية مع رصيدها المجمّع (في المخزن المختار او كل المخازن) ومتوسط كلفتها: الاصناف
     * الاب، او ألوان مجموعة سعر واحدة اذا اختيرت.
     */
    public function query(?int $warehouseId, ?int $priceGroupId = null): Builder
    {
        $products = (new Product)->getTable();
        $balances = DB::table(config('accounting.table_prefix').'stock_balances')
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as quantity, SUM(weight) as weight');

        return Product::query()
            ->with(['translations', 'parent.translations', 'media'])
            ->leftJoinSub($balances, 'balances', 'balances.product_id', '=', $products.'.id')
            ->leftJoin((new ProductCost)->getTable().' as costs', 'costs.product_id', '=', $products.'.id')
            ->where(fn (Builder $query) => $query->whereNull($products.'.item_type')->orWhere($products.'.item_type', '!=', ItemType::SERVICE->value))
            ->when($priceGroupId, fn (Builder $query) => $query->where($products.'.category_finish_id', $priceGroupId), fn (Builder $query) => $query->whereNull($products.'.parent_id'))
            ->select($products.'.*')
            ->selectRaw('COALESCE(balances.quantity, 0) as stock_on_hand, COALESCE(balances.weight, 0) as stock_weight, COALESCE(costs.average_cost, 0) as average_cost');
    }

    /**
     * «فقط الاصناف التي لها رصيد او حركة»: لها صف رصيد، او صنف اب لبعض ألوانه رصيد.
     */
    public function applyWithStock(Builder $query, ?int $warehouseId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNotNull('balances.product_id')
            ->orWhereIn((new Product)->getTable().'.id', $this->parentsWithStock($warehouseId)));
    }

    /**
     * رصيد (التصنيف × اللون) المختار: مجموع ألوانه، لسطر المجموع فوق الجدول.
     *
     * @return array{on_hand:string, weight:string, value:?string, variations:int}|null
     */
    public function groupTotal(?int $priceGroupId, ?int $warehouseId, bool $canSeeCost): ?array
    {
        $total = $priceGroupId ? ($this->levels->groupLevels([$priceGroupId], $warehouseId)[$priceGroupId] ?? null) : null;

        return $total ? [
            'on_hand' => Quantity::format($total['on_hand']),
            'weight' => Quantity::format($total['weight']),
            'value' => $canSeeCost ? Quantity::format(round($total['value'], 2)) : null,
            'variations' => $total['variations'],
        ] : null;
    }

    /**
     * حدود الطلب للاصناف الفعلية؛ الصنف الاب يجمع ألواناً مختلفة فلا يُقاس بها.
     */
    public function applyStatus(Builder $query, string $status): Builder
    {
        return StockStatus::applyFilter($query->whereNotIn((new Product)->getTable().'.id', Product::query()->select('parent_id')->whereNotNull('parent_id')), $status);
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
        $parentLevels = $this->levels->parentLevels($ids, $warehouseId);
        $values = $canSeeCost ? $this->levels->stockValues($ids, $warehouseId) : [];
        $locations = $warehouseId ? $this->levels->locations($ids, $warehouseId) : [];

        return $products->map(function (Product $product) use ($levels, $parentLevels, $values, $locations, $warehouseId, $canSeeCost) {
            $main = $parentLevels[$product->id] ?? null;
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
                'detail' => $main ? __('Total of :count colours', ['count' => $main['variations']]) : $product->parent?->name,
                'is_parent' => (bool) $main,
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
                'average_cost' => $canSeeCost && ! $main ? Quantity::format($product->average_cost, 4) : null,
                'stock_value' => $canSeeCost ? Quantity::format(match (true) {
                    (bool) $main => round((float) $main['value'], 2),
                    default => round((float) ($values[$product->id] ?? 0), 2),
                }) : null,
            ];
        })->values()->all();
    }

    /**
     * الاصناف الاب التي لبعض ألوانها رصيد في المخزن المختار.
     */
    private function parentsWithStock(?int $warehouseId): QueryBuilder
    {
        return DB::table(config('accounting.table_prefix').'stock_balances as variation_balances')
            ->join((new Product)->getTable().' as variations', 'variations.id', '=', 'variation_balances.product_id')
            ->whereNotNull('variations.parent_id')
            ->when($warehouseId, fn ($query) => $query->where('variation_balances.warehouse_id', $warehouseId))
            ->select('variations.parent_id');
    }
}
