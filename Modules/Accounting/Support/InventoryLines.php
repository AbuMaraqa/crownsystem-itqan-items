<?php

namespace Modules\Accounting\Support;

use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Entities\WarehouseLocation;
use Modules\Accounting\Services\StockLevelService;
use Modules\Accounting\Services\WarehouseLayoutService;
use Modules\Items\Entities\Product;
use Modules\Items\Support\ProductSearch;
use Modules\Items\Support\Search\SearchFilters;

/**
 * سطور مستندات المخزن في لوحة Vue: تجميع السطر تحت صنفه الرئيسي (ItemLineGroups)، وبحث
 * الاصناف التي تُختار في المستند، وسطر الصنف المضاف، وما يعرضه الجدول عن المخزن المختار
 * (المواقع، الارصدة، اماكن الصنف، الموقع الاساسي). مصدر واحد لصفحات المستندات الاربعة.
 */
final class InventoryLines
{
    /**
     * سطور سند الاستلام/التسليم: اسم الصنف الرئيسي في $labelKey ورقم سطر الطلبية في $parentKey.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function byLabel(array $lines, string $labelKey, string $parentKey): array
    {
        return self::tag($lines, ItemLineGroups::byLabel($lines, $labelKey, $parentKey));
    }

    /**
     * سطور اصناف مسطّحة (تحويل، تسوية، جرد): الصنف الفرعي تحت صنفه الرئيسي.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function byMainItem(array $lines): array
    {
        return self::tag($lines, ItemLineGroups::byMainItem($lines));
    }

    /**
     * بحث صنف لسطر مستند مخزن (بالاسم او الباركود؛ Enter يضيف اول نتيجة): الاصناف السلعية
     * فقط، والصنف الرئيسي لا يُختار لان المخزون على اصنافه الفرعية.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function search(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $ids = ProductSearch::find($term, 15, new SearchFilters(itemType: 'goods'))->ids;

        return SelectOptions::from(ProductSearch::labels(array_values(array_diff($ids, ProductSearch::idsWithSubProducts($ids)))));
    }

    /**
     * سطر صنف جديد في التحويل او التسوية، او null للخدمة او الصنف غير الموجود.
     *
     * @return array<string, mixed>|null
     */
    public static function productLine(int $productId): ?array
    {
        $product = Product::query()->with('translations')->find($productId);

        if (! $product || $product->isService()) {
            return null;
        }

        return self::byMainItem([[
            'product_id' => $product->id,
            'barcode' => $product->barcode,
            'description' => $product->name,
        ]])[0];
    }

    /**
     * ما يحتاجه جدول السطور عن المخزن المختار: خيارات المواقع، رصيد كل صنف، اماكنه
     * ورصيد كل مكان، وموقعه الاساسي.
     *
     * @param  array<int, int|string>  $productIds
     * @return array{locations: list<array{value: int|string, label: string}>, levels: object, placements: object, primary: object}
     */
    public static function warehouseContext(?int $warehouseId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if (! $warehouseId || ! Warehouse::query()->whereKey($warehouseId)->exists()) {
            return ['locations' => [], 'levels' => [], 'placements' => [], 'primary' => []];
        }

        $stock = app(StockLevelService::class);

        return [
            'locations' => SelectOptions::from(WarehouseLocation::options($warehouseId)),
            'levels' => (object) $stock->levels($productIds, $warehouseId),
            'placements' => (object) $stock->locations($productIds, $warehouseId),
            'primary' => (object) app(WarehouseLayoutService::class)->primaryLocationIds($productIds, $warehouseId),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array{key: string, main: bool, label: ?string, lines: array<int, array<string, mixed>>}>  $groups
     * @return list<array<string, mixed>>
     */
    private static function tag(array $lines, array $groups): array
    {
        foreach ($groups as $group) {
            foreach (array_keys($group['lines']) as $index) {
                $lines[$index]['group_key'] = $group['main'] ? $group['key'] : null;
                $lines[$index]['group_label'] = $group['main'] ? $group['label'] : null;
            }
        }

        return array_values($lines);
    }
}
