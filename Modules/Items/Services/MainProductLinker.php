<?php

namespace Modules\Items\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ProductStatus;

/**
 * ربط اصناف الوزن الفرعية بصنفها الرئيسي من شجرة النظام القديم.
 *
 * كل صنف مستورد يحفظ ItemParent في meta_data.legacy_parent_id، والصنف الرئيسي
 * هو الصنف الذي كوده (sku) هو ذلك المعرّف. المستورد ينشئ a_al صنفاً حقيقياً، اما
 * مقطع b_al تحت فئة مباشرة فيُنشأ لفئته صنف رئيسي عند الطلب (createFromCategories)
 * ليحمل سعر الكيلوغرام.
 *
 * يُربط فقط الصنف الفرعي المسعّر بالوزن والذي لا رئيسي له بعد، فلا يُلغى ربط
 * عُدّل يدوياً.
 */
class MainProductLinker
{
    /**
     * @return array{stats: array<string, int|bool>, unlinked: array<int, array{product_id: int, sku: string|null, name: string|null, legacy_parent_id: string, reason: string}>}
     */
    public function link(bool $dryRun = true, bool $createFromCategories = false, ?callable $logger = null): array
    {
        $stats = [
            'dry_run' => $dryRun,
            'candidates' => 0,
            'linked' => 0,
            'main_products_created' => 0,
            'parent_is_category' => 0,
            'parent_not_weight' => 0,
            'parent_missing' => 0,
        ];

        $unlinked = [];
        $createdMainIds = [];

        Product::query()
            ->select(['id', 'sku', 'meta_data', 'main_product_id'])
            ->with('translations:id,product_id,locale,name')
            ->where('pricing_type', 'weight')
            ->whereNull('main_product_id')
            ->whereNull('parent_id')
            ->whereNotNull('meta_data')
            ->chunkById(1000, function ($products) use ($dryRun, $createFromCategories, &$stats, &$unlinked, &$createdMainIds, $logger): void {
                $parents = [];

                foreach ($products as $product) {
                    // a_al صنف رئيسي لا مقطع: ابوه فئة، فلا يُنشأ منها رئيسي يدخل تحته.
                    if (($product->meta_data['legacy_item_type'] ?? null) === 'a_al') {
                        continue;
                    }

                    $parent = trim((string) ($product->meta_data['legacy_parent_id'] ?? ''));

                    if ($parent !== '' && $parent !== '0' && $parent !== (string) $product->sku) {
                        $parents[$product->id] = $parent;
                    }
                }

                if ($parents === []) {
                    return;
                }

                $stats['candidates'] += count($parents);

                $mainProducts = Product::query()
                    ->select(['id', 'sku', 'pricing_type'])
                    ->whereIn('sku', array_values(array_unique($parents)))
                    ->whereNull('parent_id')
                    ->get()
                    ->keyBy(fn (Product $main): string => (string) $main->sku);

                $categories = $this->legacyCategories(array_diff(array_unique($parents), $mainProducts->keys()->all()));
                $links = [];

                foreach ($parents as $productId => $parent) {
                    $product = $products->firstWhere('id', $productId);
                    $main = $mainProducts->get($parent);

                    if (! $main && isset($categories[$parent])) {
                        if (! $createFromCategories) {
                            $stats['parent_is_category']++;
                            $unlinked[] = $this->unlinkedRow($product, $parent, 'parent_is_category');

                            continue;
                        }

                        $main = $createdMainIds[$parent] ?? null;

                        if (! $main) {
                            $main = $dryRun
                                ? new Product(['sku' => $parent, 'pricing_type' => 'weight'])
                                : $this->createMainFromCategory($categories[$parent], $parent);
                            $createdMainIds[$parent] = $main;
                            $stats['main_products_created']++;
                        }
                    }

                    if (! $main) {
                        $stats['parent_missing']++;
                        $unlinked[] = $this->unlinkedRow($product, $parent, 'parent_missing');

                        continue;
                    }

                    // الصنف الرئيسي يحمل سعر الكيلوغرام، فلا يكون صنف كمية.
                    if ($main->pricing_type !== 'weight') {
                        $stats['parent_not_weight']++;
                        $unlinked[] = $this->unlinkedRow($product, $parent, 'parent_not_weight');

                        continue;
                    }

                    $links[$main->id ?? 0][] = $productId;
                    $stats['linked']++;
                }

                if (! $dryRun) {
                    foreach ($links as $mainId => $productIds) {
                        Product::query()->whereKey($productIds)->update(['main_product_id' => $mainId]);
                    }
                }

                if ($logger) {
                    $logger("Processed up to product #{$products->last()->id}: linked {$stats['linked']}");
                }
            });

        return ['stats' => $stats, 'unlinked' => $unlinked];
    }

    /**
     * التصنيفات التي انشأها المستورد من عقد النظام القديم، مفهرسة بالمعرّف القديم.
     *
     * @param  array<int, string>  $legacyIds
     * @return array<string, Category>
     */
    private function legacyCategories(array $legacyIds): array
    {
        if ($legacyIds === []) {
            return [];
        }

        $slugs = [];

        foreach ($legacyIds as $legacyId) {
            $slugs[$this->legacySlug('old-cat', $legacyId)] = $legacyId;
        }

        return Category::query()
            ->with('translations')
            ->whereIn('slug', array_keys($slugs))
            ->get()
            ->mapWithKeys(fn (Category $category): array => [$slugs[$category->slug] => $category])
            ->all();
    }

    private function createMainFromCategory(Category $category, string $legacyId): Product
    {
        return DB::transaction(function () use ($category, $legacyId): Product {
            $product = new Product([
                'slug' => $this->legacySlug('old-main', $legacyId).'-'.Str::lower(Str::random(4)),
                'sku' => $legacyId,
                'base_price' => 0,
                'pricing_type' => 'weight',
                'status' => ProductStatus::PUBLISHED,
                'is_active' => 1,
                'meta_data' => ['legacy_item_id' => $legacyId, 'created_from_category_id' => $category->id],
            ]);

            $product->save();

            foreach ($category->translations as $translation) {
                $product->translateOrNew($translation->locale)->name = $translation->name;
            }

            $product->save();
            // في نفس تصنيف اصنافه الفرعية، فيظهر معها في قائمة التصنيف.
            $product->categories()->syncWithoutDetaching([$category->id]);

            return $product;
        });
    }

    /**
     * @return array{product_id: int, sku: string|null, name: string|null, legacy_parent_id: string, reason: string}
     */
    private function unlinkedRow(Product $product, string $parent, string $reason): array
    {
        return [
            'product_id' => (int) $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'legacy_parent_id' => $parent,
            'reason' => $reason,
        ];
    }

    /**
     * نفس صيغة LegacyItemsImporter::legacySlug().
     */
    private function legacySlug(string $prefix, string $id): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $id), '-');

        return strtolower($prefix.'-'.($clean !== '' ? $clean : sha1($id)));
    }
}
