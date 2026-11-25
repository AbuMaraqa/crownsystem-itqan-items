<?php

namespace Modules\Accounting\Support;

use Modules\Items\Entities\Product;
use Modules\Items\Support\PriceGroupSearch;

/**
 * بيانات بطاقة الصنف كما ترسل الى Vue، مصدر واحد لصفحة بطاقة الصنف (ItemStockCardController)
 * ونافذة تفاصيل الصنف في جدول الاصناف (ItemsGridController::details): تعريف الصنف، الارصدة
 * والحالة والكلفة، الرصيد في كل مخزن، والطلبيات المفتوحة.
 */
final class ItemCardData
{
    /**
     * @return array<string, mixed>
     */
    public static function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'image' => $product->image ?: null,
            'price_group' => $product->category_finish_id ? (PriceGroupSearch::labels([$product->category_finish_id])[(int) $product->category_finish_id] ?? null) : null,
            'barcode' => $product->barcode,
            'sku' => $product->sku,
            'weight_per_meter' => (float) $product->weight_per_meter ? Quantity::format($product->weight_per_meter, 3) : null,
            'length' => (float) $product->length ? Quantity::format($product->length, 3) : null,
            'shelf' => $product->shelf,
            'max_limit' => Quantity::format($product->max_limit),
            'min_limit_1' => Quantity::format($product->min_limit_1),
            'min_limit_2' => Quantity::format($product->min_limit_2),
        ];
    }

    /**
     * الارصدة والحالة والكلفة والمخازن والطلبيات المفتوحة من ItemStockCardService::summary().
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public static function summary(array $summary): array
    {
        $levels = $summary['levels'];
        $main = $summary['parentLevels'];
        $fmt = fn ($value, int $decimals = 2): string => Quantity::format($value, $decimals);
        $canSeeCost = (bool) auth()->user()?->can('Stock Value View');
        $cost = null;

        if ($canSeeCost && ! $main && $summary['cost']) {
            $average = (float) $summary['cost']->average_cost;
            $value = (float) $levels['on_hand'] * $average;
            // المخزون يُعدّ بالقطع: صنف الوزن يعرض ايضاً كلفة الكغم (القيمة ÷ الوزن).
            $cost = ['average' => $fmt($average, 4), 'per_kg' => (float) $levels['weight'] > 0 ? $fmt($value / (float) $levels['weight'], 4) : null, 'value' => $fmt($value)];
        }

        return [
            'levels' => [
                'on_hand' => $fmt($levels['on_hand']),
                'reserved' => $fmt($levels['reserved']),
                'incoming' => $fmt($levels['incoming']),
                'available' => $fmt($levels['available']),
                'available_negative' => (float) $levels['available'] < 0,
                'weight' => (float) $levels['weight'] > 0 ? $fmt($levels['weight']) : null,
            ],
            'status' => ['label' => $summary['status']->getLabel(), 'tone' => StatusBadge::tone($summary['status']->color())],
            'value' => $canSeeCost && $main ? $fmt($main['value']) : null,
            'cost' => $cost,
            'main' => $main ? [
                'sub_items' => (int) $main['variations'],
                'on_hand' => $fmt($main['on_hand']),
                'weight' => $fmt($main['weight']),
                'reserved' => $fmt($main['reserved']),
                'incoming' => $fmt($main['incoming']),
                'available' => $fmt($main['available']),
                'value' => $canSeeCost ? $fmt($main['value']) : null,
            ] : null,
            'warehouses' => $summary['byWarehouse']->map(fn ($row) => [
                'id' => (int) $row->warehouse_id,
                'name' => $row->warehouse?->name,
                'quantity' => $fmt($row->quantity),
                'weight' => (float) $row->weight ? $fmt($row->weight) : null,
            ])->values()->all(),
            'sales_orders' => $summary['salesOrders']->map(fn (array $row) => [...$row, 'quantity' => $fmt($row['quantity'])])->values()->all(),
            'purchase_orders' => $summary['purchaseOrders']->map(fn (array $row) => [...$row, 'quantity' => $fmt($row['quantity'])])->values()->all(),
        ];
    }
}
