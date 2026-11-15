<?php

namespace Modules\Items\Services;

use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductAccessory;

/**
 * ملحقات الصنف: اصناف مخزنية تُقترح مع الصنف عند بيعه (اقتراح لا إلزام)، ولكل ملحق
 * كمية مقترحة لكل وحدة من الصنف. مصدر واحد لقراءتها وحفظها في بطاقة الصنف.
 */
class ProductAccessoryService
{
    /**
     * صفوف الملحقات كما يعرضها النموذج.
     *
     * @return array<int, array{id: int, accessory_product_id: int, quantity: string}>
     */
    public function state(Product $product): array
    {
        return $product->accessories()
            ->get(['id', 'accessory_product_id', 'quantity'])
            ->map(fn ($accessory): array => [
                'id' => (int) $accessory->id,
                'accessory_product_id' => (int) $accessory->accessory_product_id,
                'quantity' => rtrim(rtrim((string) $accessory->quantity, '0'), '.') ?: '1',
            ])
            ->all();
    }

    /**
     * يستبدل ملحقات الصنف بما في النموذج، بترتيبه. الصف الفارغ او الصنف نفسه او المكرر يُتجاهل.
     *
     * @param  array<int|string, mixed>  $rows
     */
    public function sync(Product $product, array $rows): void
    {
        $rows = collect($rows)
            ->filter(fn ($row): bool => is_array($row) && filled($row['accessory_product_id'] ?? null)
                && (int) $row['accessory_product_id'] !== (int) $product->id)
            ->unique(fn (array $row): int => (int) $row['accessory_product_id'])
            ->values();

        $keepIds = $rows->map(fn (array $row): int => (int) $row['accessory_product_id'])->all();

        ProductAccessory::query()
            ->where('product_id', $product->id)
            ->whereNotIn('accessory_product_id', $keepIds)
            ->delete();

        foreach ($rows as $index => $row) {
            $product->accessories()->updateOrCreate(
                ['accessory_product_id' => (int) $row['accessory_product_id']],
                [
                    'quantity' => (float) ($row['quantity'] ?? 1) > 0 ? $row['quantity'] : 1,
                    'sort_order' => $index,
                ],
            );
        }
    }
}
