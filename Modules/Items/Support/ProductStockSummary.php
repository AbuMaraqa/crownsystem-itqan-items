<?php

namespace Modules\Items\Support;

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Enums\StockAdjustmentType;
use Modules\Accounting\Services\StockLevelService;
use Modules\Accounting\Support\Quantity;
use Modules\Accounting\Support\StatusBadge;
use Modules\Items\Entities\Product;

/**
 * ملخص مخزون الصنف في تبويب «المخزون» ببطاقة الصنف (صفحة Vue)، للعرض فقط: الكمية لا تتغير الا
 * بسندات المخزون. مصدره
 * StockLevelService::productSummary.
 */
final class ProductStockSummary
{
    /**
     * @return array<string, mixed>|null null بلا صلاحية «Stock View List» او لصنف جديد
     */
    public static function of(?Product $product): ?array
    {
        $user = auth()->user();

        if (! $product?->exists || ! $user?->can('Stock View List')) {
            return null;
        }

        $summary = app(StockLevelService::class)->productSummary($product);
        $levels = $summary['levels'];
        $isParent = (bool) $summary['parentLevels'];
        $canSeeValue = $user->can('Stock Value View');
        $fmt = fn ($value, int $decimals = 2): string => Quantity::format($value, $decimals);

        $cost = null;

        if ($canSeeValue && ! $isParent && $summary['cost']) {
            $average = (float) $summary['cost']->average_cost;
            $value = (float) $levels['on_hand'] * $average;

            $cost = [
                'average' => $fmt($average, 4),
                // صنف الوزن: تكلفة الكغم (القيمة ÷ الوزن) هي ما يُقارَن بسعر الشراء.
                'per_kg' => (float) $levels['weight'] > 0 ? $fmt($value / (float) $levels['weight'], 4) : null,
                'value' => $fmt($value),
            ];
        }

        return [
            'is_parent' => $isParent,
            'levels' => [
                'on_hand' => $fmt($levels['on_hand']),
                'reserved' => $fmt($levels['reserved']),
                'incoming' => $fmt($levels['incoming']),
                'available' => $fmt($levels['available']),
                'available_negative' => (float) $levels['available'] < 0,
                'weight' => (float) $levels['weight'] > 0 ? $fmt($levels['weight']) : null,
            ],
            'status' => ['label' => (string) $summary['status']->getLabel(), 'tone' => StatusBadge::tone($summary['status']->color())],
            'value' => $canSeeValue && $isParent ? $fmt($summary['parentLevels']['value']) : null,
            'cost' => $cost,
            'warehouses' => array_map(fn (array $row) => [
                'name' => $row['name'],
                'location' => $row['location'],
                'quantity' => $fmt($row['quantity']),
                'weight' => $row['weight'] > 0 ? $fmt($row['weight']) : null,
            ], $summary['warehouses']),
        ];
    }

    /**
     * رصيد كل لون (متغيّر) للصنف في تبويب «المخزون»، باستعلام واحد. فارغ بلا صلاحية «Stock View List».
     *
     * @return array<int, array{on_hand: string, available: string, available_negative: bool, weight: ?string}> بمفتاح معرّف المتغيّر
     */
    public static function variants(Product $product): array
    {
        if (! auth()->user()?->can('Stock View List') || ! $product->exists) {
            return [];
        }

        $ids = $product->relationLoaded('variations') ? $product->variations->modelKeys() : $product->variations()->pluck('id')->all();
        $fmt = fn ($value): string => Quantity::format($value, 2);

        return array_map(fn (array $levels) => [
            'on_hand' => $fmt($levels['on_hand']),
            'available' => $fmt($levels['available']),
            'available_negative' => $levels['available'] < 0,
            'weight' => $levels['weight'] > 0 ? $fmt($levels['weight']) : null,
        ], app(StockLevelService::class)->levels($ids));
    }

    /**
     * روابط المخزون في التبويب: بطاقة الصنف المخزنية والرصيد الافتتاحي (لغير الصنف الرئيسي).
     *
     * @return list<array{label: string, href: string}>
     */
    public static function links(?Product $product): array
    {
        $user = auth()->user();

        if (! $product?->exists || ! $user) {
            return [];
        }

        $links = [];

        if ($user->can('Stock Item Card View') && Route::has('accounting.inventory.item-card')) {
            $links[] = ['label' => __('Item Stock Card'), 'href' => route('accounting.inventory.item-card', $product)];
        }

        // الصنف الاب لا رصيد له: رصيده على ألوانه، فرصيدها الافتتاحي يُدخل على كل لون.
        $isParent = Product::query()->where('parent_id', $product->id)->exists();

        if (! $isParent && $user->can('Stock Adjustment Create') && Route::has('accounting.inventory.adjustments.add')) {
            $links[] = [
                'label' => __('Opening Balance'),
                'href' => route('accounting.inventory.adjustments.add', ['type' => StockAdjustmentType::OPENING->value, 'product' => $product->id]),
            ];
        }

        return $links;
    }
}
