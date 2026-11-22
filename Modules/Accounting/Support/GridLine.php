<?php

namespace Modules\Accounting\Support;

use Modules\Items\Entities\Product;
use Modules\Items\Support\PriceGroupSearch;

/**
 * ما يملك سطر جدول الاصناف: صنف (product_id)، او مجموعة سعر (category_finish_id) بلا صنف، وهو
 * السطر الرئيسي لصنف الوزن في المرحلة 2 («الوبين — أبيض» وقطعه ألوان مقاطعه). مصدر واحد لخدمات
 * المستندات وطلبات الحفظ.
 */
final class GridLine
{
    /**
     * @param  array<string, mixed>  $item
     */
    public static function hasOwner(array $item): bool
    {
        return filled($item['product_id'] ?? null) || filled($item['category_finish_id'] ?? null);
    }

    /**
     * مجموعة سعر السطر ان كان سطر مجموعة (بلا صنف)، والا null.
     *
     * @param  array<string, mixed>  $item
     */
    public static function groupId(array $item): ?int
    {
        return blank($item['product_id'] ?? null) && filled($item['category_finish_id'] ?? null) ? (int) $item['category_finish_id'] : null;
    }

    /**
     * صنف السطر محمّلاً بترجمته، او null لسطر المجموعة.
     *
     * @param  array<string, mixed>  $item
     */
    public static function product(array $item): ?Product
    {
        return filled($item['product_id'] ?? null) ? Product::query()->with('translations')->find($item['product_id']) : null;
    }

    /**
     * السطر يُسعَّر بالكيلو بقطعه: صنف وزن، او مجموعة سعر.
     *
     * @param  array<string, mixed>  $item
     */
    public static function isWeight(array $item, ?Product $product): bool
    {
        return self::groupId($item) !== null || $product?->pricing_type === 'weight';
    }

    /**
     * وصف السطر المحفوظ: ما كُتب، او اسم الصنف، او «التصنيف — اللون».
     *
     * @param  array<string, mixed>  $item
     */
    public static function description(array $item, ?Product $product): ?string
    {
        if (filled($item['description'] ?? null)) {
            return (string) $item['description'];
        }

        if ($product) {
            return $product->name;
        }

        $groupId = self::groupId($item);

        return $groupId ? (PriceGroupSearch::labels([$groupId])[$groupId] ?? null) : null;
    }
}
