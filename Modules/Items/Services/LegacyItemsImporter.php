<?php

declare(strict_types=1);

namespace Modules\Items\Services;

use Illuminate\Support\Facades\DB;
use Modules\Items\Support\LegacyItemFields;
use Modules\Items\Support\Search\ItemsSearchIndex;
use PDO;
use RuntimeException;
use Throwable;

class LegacyItemsImporter
{
    /** أعمدة جدول items في إتقان بترتيبها. */
    public const LEGACY_COLUMNS = [
        'ItemId', 'ItemString', 'Barcode', 'ItemType', 'ItemCost', 'itemunit', 'ItemParent', 'ItemDefStore',
        'ItemMax', 'ItemMin1', 'ItemMin2', 'ExpDays', 'ItemNote', 'ChRange', 'Prop1', 'Prop2', 'Prop3',
        'Prop4', 'Prop5', 'Prop6', 'prop7', 'prop8', 'prop9', 'isActive',
    ];

    /**
     * Import the old `items` SQL dump into the current Items module tables.
     *
     * @return array<string, int|bool|string>
     */
    public function import(string $file, bool $dryRun = false, bool $allNodesAsProducts = false, ?callable $logger = null): array
    {
        ini_set('memory_limit', env('ITEMS_SEED_MEMORY_LIMIT', '2048M'));
        set_time_limit(0);

        if (! is_file($file)) {
            throw new RuntimeException("SQL file not found: {$file}");
        }

        $this->log($logger, "Reading old items SQL: {$file}");

        return $this->importItems($this->readOldItems($file), $file, $dryRun, $allNodesAsProducts, $logger);
    }

    /**
     * صفوف جدول items في إتقان (من ملفاته مباشرة او من اي مصدر)، بأسماء أعمدته الاصلية.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return array<string, int|bool|string>
     */
    public function importRows(iterable $rows, string $source, bool $dryRun = false, bool $allNodesAsProducts = false, ?callable $logger = null): array
    {
        ini_set('memory_limit', env('ITEMS_SEED_MEMORY_LIMIT', '2048M'));
        set_time_limit(0);

        $items = [];

        foreach ($rows as $row) {
            $item = [];

            foreach (self::LEGACY_COLUMNS as $column) {
                $value = $row[$column] ?? null;
                $item[$column] = $value === null || is_string($value) ? $value : $this->scalarString($value);
            }

            $id = $this->normalizeLegacyId($item['ItemId']);

            if ($id !== '') {
                $item['ItemId'] = $id;
                $items[$this->legacyKey($id)] = $item;
            }
        }

        return $this->importItems($items, $source, $dryRun, $allNodesAsProducts, $logger);
    }

    /**
     * @param  array<string, array<string, mixed>>  $items  مفتاحها legacyKey(ItemId)
     * @return array<string, int|bool|string>
     */
    private function importItems(array $items, string $file, bool $dryRun, bool $allNodesAsProducts, ?callable $logger): array
    {
        $pdo = DB::connection()->getPdo();
        $now = now()->format('Y-m-d H:i:s');

        foreach ($this->requiredTables() as $table) {
            if (! $this->tableExists($pdo, $table)) {
                throw new RuntimeException("Missing table: {$table}");
            }
        }

        if (! $items) {
            throw new RuntimeException('No rows were found in the old `items` table.');
        }

        [$categoryOldIds, $productOldIds, $inactiveRows, $missingNames] = $this->classifyOldItems($items, $allNodesAsProducts);

        $summary = [
            'source_file' => $file,
            'old_rows' => count($items),
            'old_category_nodes' => count($categoryOldIds),
            'old_product_rows_selected' => count($productOldIds),
            'inactive_old_rows' => $inactiveRows,
            'rows_with_missing_names' => $missingNames,
            'current_db_products' => (int) $pdo->query('SELECT COUNT(*) FROM ' . $this->tableSql('products'))->fetchColumn(),
            'current_db_categories' => (int) $pdo->query('SELECT COUNT(*) FROM ' . $this->tableSql('categories'))->fetchColumn(),
        ];

        foreach ($summary as $key => $value) {
            $this->log($logger, "{$key}: {$value}");
        }

        if ($dryRun) {
            $this->log($logger, 'Dry run only. No database changes were made.');

            return $summary + ['dry_run' => true];
        }

        $stats = $this->bulkImport($pdo, $items, $categoryOldIds, $productOldIds, $now, $logger);
        $this->log($logger, 'Import complete.');

        // الاستيراد يكتب في الجداول مباشرة دون احداث النماذج، فيُعاد بناء فهرس البحث.
        $this->log($logger, 'Rebuilding the items search index...');
        $stats['search_indexed'] = app(ItemsSearchIndex::class)->rebuild();

        return $summary + $stats + ['dry_run' => false];
    }

    /**
     * Backfill the `pricing_type` column (simple vs weight) from the legacy `ItemType`
     * column, matched by SKU (= legacy ItemId). Only touches `pricing_type` — every
     * other product column is left as-is, so edits made since the original import are
     * not overwritten.
     *
     * @return array<string, int|string>
     */
    public function syncPricingTypes(string $file, bool $dryRun = false, ?callable $logger = null): array
    {
        ini_set('memory_limit', env('ITEMS_SEED_MEMORY_LIMIT', '2048M'));
        set_time_limit(0);

        if (! is_file($file)) {
            throw new RuntimeException("SQL file not found: {$file}");
        }

        $pdo = DB::connection()->getPdo();

        $this->log($logger, "Reading old items SQL: {$file}");
        $items = $this->readOldItems($file);

        if (! $items) {
            throw new RuntimeException('No rows were found in the old `items` dump.');
        }

        $byPricingType = ['simple' => [], 'weight' => []];

        foreach ($items as $item) {
            $sku = $this->normalizeLegacyId($item['ItemId'] ?? null);

            if ($sku === '' || $sku === '0') {
                continue;
            }

            $byPricingType[$this->mapPricingType($item['ItemType'] ?? null)][] = $sku;
        }

        $stats = [
            'source_file' => $file,
            'old_rows' => count($items),
            'skus_mapped_simple' => count($byPricingType['simple']),
            'skus_mapped_weight' => count($byPricingType['weight']),
        ];

        foreach ($stats as $key => $value) {
            $this->log($logger, "{$key}: {$value}");
        }

        if ($dryRun) {
            $this->log($logger, 'Dry run only. No database changes were made.');

            return $stats + ['dry_run' => true, 'products_updated_simple' => 0, 'products_updated_weight' => 0];
        }

        $updated = [
            'products_updated_simple' => $this->updatePricingTypeForSkus($pdo, $byPricingType['simple'], 'simple'),
            'products_updated_weight' => $this->updatePricingTypeForSkus($pdo, $byPricingType['weight'], 'weight'),
        ];

        $this->log($logger, 'Pricing type sync complete.');

        return $stats + $updated + ['dry_run' => false];
    }

    /**
     * `b_al` / `a_al` are aluminum profile items tracked and priced by weight;
     * everything else (regular hardware/store items) is a simple quantity item.
     */
    private function mapPricingType(?string $itemType): string
    {
        return in_array($itemType, ['a_al', 'b_al'], true) ? 'weight' : 'simple';
    }

    /**
     * @param  array<int, string>  $skus
     */
    private function updatePricingTypeForSkus(PDO $pdo, array $skus, string $pricingType): int
    {
        $updated = 0;

        foreach (array_chunk(array_values($skus), 1000) as $chunk) {
            if (! $chunk) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare(
                'UPDATE '.$this->tableSql('products')
                ." SET pricing_type = ? WHERE sku IN ({$placeholders}) AND pricing_type != ?"
            );
            $stmt->execute([$pricingType, ...$chunk, $pricingType]);
            $updated += $stmt->rowCount();
        }

        return $updated;
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [
            $this->table('products'),
            $this->table('product_translations'),
            $this->table('categories'),
            $this->table('category_translations'),
            $this->table('products_categories'),
            $this->table('product_barcodes'),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $items
     *
     * @return array{0: array<string, string>, 1: array<int, string>, 2: int, 3: int}
     */
    private function classifyOldItems(array $items, bool $allNodesAsProducts): array
    {
        $parentIdsWithChildren = [];
        $inactiveRows = 0;
        $missingNames = 0;

        foreach ($items as $item) {
            $id = $this->normalizeLegacyId($item['ItemId'] ?? null);

            if (! $this->isActive($item)) {
                $inactiveRows++;
            }

            if (trim((string) ($item['ItemString'] ?? '')) === '') {
                $missingNames++;
            }

            $parent = $this->normalizeLegacyId($item['ItemParent'] ?? null);
            if ($parent !== '' && $parent !== $id && isset($items[$this->legacyKey($parent)])) {
                $parentIdsWithChildren[$this->legacyKey($parent)] = $parent;
            }
        }

        $categoryOldIds = [];
        foreach ($parentIdsWithChildren as $parentKey => $parent) {
            if ($parent !== '0' && isset($items[$parentKey])) {
                $categoryOldIds[$parentKey] = $parent;
            }
        }

        // c_cont فئة دائماً، ولو بلا ابناء.
        foreach ($items as $item) {
            $id = $this->normalizeLegacyId($item['ItemId'] ?? null);

            if ($id !== '' && $id !== '0' && $this->isCategoryRow($item)) {
                $categoryOldIds[$this->legacyKey($id)] = $id;
            }
        }

        $productOldIds = [];
        foreach ($items as $item) {
            $id = $this->normalizeLegacyId($item['ItemId'] ?? null);

            if ($id === '0' || $this->isCategoryRow($item)) {
                continue;
            }

            // a_al له ابناء صنف حقيقي بتكلفته وباركوده، وله توأم فئة يبقي شكل الشجرة.
            if ($allNodesAsProducts || ! isset($categoryOldIds[$this->legacyKey($id)]) || $this->isMainItemRow($item)) {
                $productOldIds[] = $id;
            }
        }

        return [$categoryOldIds, $productOldIds, $inactiveRows, $missingNames];
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param array<string, string> $categoryOldIds
     * @param array<int, string> $productOldIds
     *
     * @return array<string, int>
     */
    private function bulkImport(PDO $pdo, array $items, array $categoryOldIds, array $productOldIds, string $now, ?callable $logger): array
    {
        $stats = [
            'categories_created' => 0,
            'categories_updated' => 0,
            'category_translations_created' => 0,
            'category_translations_updated' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'product_translations_created' => 0,
            'product_translations_replaced' => 0,
            'product_category_links_created' => 0,
            'product_category_links_replaced' => 0,
            'barcode_rows_created' => 0,
            'units_created' => 0,
            'english_names_created' => 0,
            'products_not_imported' => 0,
            'first_barcode_pack_quantity_ignored' => 0,
        ];

        $categoryOldIdList = array_values($categoryOldIds);
        $existingCategorySlugs = $this->fetchExistingValues(
            $pdo,
            $this->table('categories'),
            'slug',
            array_map(fn (string $oldId): string => $this->legacySlug('old-cat', $oldId), $categoryOldIdList)
        );
        $existingProductSkus = $this->fetchExistingValues($pdo, $this->table('products'), 'sku', $productOldIds);

        $stats['categories_updated'] = count($existingCategorySlugs);
        $stats['categories_created'] = count($categoryOldIdList) - $stats['categories_updated'];
        $stats['products_updated'] = count($existingProductSkus);
        $stats['products_created'] = count($productOldIds) - $stats['products_updated'];

        $pdo->beginTransaction();

        try {
            $this->upsertCategories($pdo, $items, $categoryOldIdList, $now);
            $categoryIdByOldId = $this->fetchIdMapByLegacySlug($pdo, $this->table('categories'), 'old-cat', $categoryOldIdList);
            $this->updateCategoryParents($pdo, $items, $categoryOldIdList, $categoryIdByOldId, $now);
            $this->upsertCategoryTranslations($pdo, $items, $categoryOldIdList, $categoryIdByOldId, $stats, $now);

            $units = app(LegacyUnitResolver::class);
            $this->upsertProducts($pdo, $items, $productOldIds, $now, $logger, $units);
            $stats['units_created'] = $units->createdCount();
            $productIdBySku = $this->fetchIdMapByColumn($pdo, $this->table('products'), 'sku', $productOldIds);

            // استيراد 1:1: كل صف قديم صنف واحد. صفان يتساويان في المفتاح الفريد يظهران هنا.
            $stats['products_not_imported'] = count($productOldIds) - count($productIdBySku);

            $productIds = array_values($productIdBySku);
            $stats['product_translations_replaced'] = $this->deleteByIds(
                $pdo,
                $this->table('product_translations'),
                'product_id',
                $productIds,
                "locale = 'ar'"
            );
            $this->insertProductTranslations($pdo, $items, $productOldIds, $productIdBySku, $stats, $now);

            $stats['product_category_links_replaced'] = $this->deleteByIds(
                $pdo,
                $this->table('products_categories'),
                'product_id',
                $productIds
            );
            $this->insertProductCategoryLinks($pdo, $items, $productOldIds, $productIdBySku, $categoryOldIds, $categoryIdByOldId, $stats, $now);
            $this->insertLegacyBarcodes($items, $productOldIds, $productIdBySku, $stats);
            $this->insertEnglishNames($items, $productOldIds, $productIdBySku, $stats);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $stats;
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param array<int, string> $categoryOldIdList
     */
    private function upsertCategories(PDO $pdo, array $items, array $categoryOldIdList, string $now): void
    {
        // رقم التصنيف هو معرّفه في النظام القديم، كما يظهر في شجرة الاصناف.
        $columns = ['slug', 'code', 'status', 'sort_order', 'parent_id', 'created_by', 'created_at', 'updated_at'];
        $rows = [];

        foreach ($categoryOldIdList as $oldId) {
            $item = $items[$this->legacyKey($oldId)];
            $rows[] = [
                $this->legacySlug('old-cat', $oldId),
                $this->limitString($oldId, 50),
                $this->isActive($item) ? 1 : 0,
                0,
                null,
                null,
                $now,
                $now,
            ];
        }

        $this->bulkUpsert(
            $pdo,
            $this->table('categories'),
            $columns,
            $rows,
            'code = VALUES(code), status = VALUES(status), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at), deleted_at = NULL',
            500
        );
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param array<int, string> $categoryOldIdList
     * @param array<string, int> $categoryIdByOldId
     */
    private function updateCategoryParents(PDO $pdo, array $items, array $categoryOldIdList, array $categoryIdByOldId, string $now): void
    {
        $updates = [];

        foreach ($categoryOldIdList as $oldId) {
            $categoryId = $categoryIdByOldId[$oldId] ?? null;
            if (! $categoryId) {
                continue;
            }

            $parentOldId = $this->normalizeLegacyId($items[$this->legacyKey($oldId)]['ItemParent'] ?? null);
            $parentId = $categoryIdByOldId[$parentOldId] ?? null;
            if ($parentId === $categoryId) {
                $parentId = null;
            }

            $updates[] = [$categoryId, $parentId];
        }

        foreach (array_chunk($updates, 500) as $chunk) {
            if (! $chunk) {
                continue;
            }

            $caseSql = 'CASE id ';
            $params = [];
            $ids = [];

            foreach ($chunk as [$id, $parentId]) {
                $caseSql .= 'WHEN ? THEN ? ';
                $params[] = $id;
                $params[] = $parentId;
                $ids[] = $id;
            }

            $caseSql .= 'END';
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params[] = $now;
            array_push($params, ...$ids);

            $pdo->prepare('UPDATE ' . $this->tableSql('categories') . " SET parent_id = {$caseSql}, updated_at = ? WHERE id IN ({$placeholders})")
                ->execute($params);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param array<int, string> $categoryOldIdList
     * @param array<string, int> $categoryIdByOldId
     * @param array<string, int> $stats
     */
    private function upsertCategoryTranslations(PDO $pdo, array $items, array $categoryOldIdList, array $categoryIdByOldId, array &$stats, string $now): void
    {
        $columns = ['category_id', 'locale', 'name', 'description', 'created_at', 'updated_at'];
        $rows = [];
        $existing = 0;

        foreach ($categoryOldIdList as $oldId) {
            $categoryId = $categoryIdByOldId[$oldId] ?? null;
            if (! $categoryId) {
                continue;
            }

            $item = $items[$this->legacyKey($oldId)];
            $rows[] = [
                $categoryId,
                'ar',
                $this->translationName($item, $oldId),
                $this->nullableString($item['ItemNote'] ?? null),
                $now,
                $now,
            ];
        }

        foreach (array_chunk(array_column($rows, 0), 1000) as $ids) {
            $existing += $this->countExistingByIds($pdo, $this->table('category_translations'), 'category_id', $ids, "locale = 'ar'");
        }

        $this->bulkUpsert(
            $pdo,
            $this->table('category_translations'),
            $columns,
            $rows,
            'name = VALUES(name), description = VALUES(description), updated_at = VALUES(updated_at), deleted_at = NULL',
            500
        );

        $stats['category_translations_updated'] = $existing;
        $stats['category_translations_created'] = count($rows) - $existing;
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param array<int, string> $productOldIds
     */
    private function upsertProducts(PDO $pdo, array $items, array $productOldIds, string $now, ?callable $logger, LegacyUnitResolver $units): void
    {
        $columns = [
            'slug',
            'barcode',
            'sku',
            'discount',
            'base_price',
            'sale_price',
            'cost_price',
            'wholesale_price',
            'stock_quantity',
            'manage_stock',
            'low_stock_threshold',
            'stock_status',
            'allow_backorder',
            'max_quantity_per_order',
            'discount_type',
            'discount_value',
            'discount_start_date',
            'discount_end_date',
            'min_quantity_discount',
            'weight',
            'weight_per_meter',
            'length',
            'shelf',
            'status',
            'is_featured',
            'is_digital',
            'meta_data',
            'created_by',
            'created_at',
            'updated_at',
            'parent_id',
            'brand_id',
            'is_active',
            // حدود المخزون من النظام القديم تُكتب عند الانشاء فقط (ليست في $updates)،
            // فلا تمحو اعادة الاستيراد ما عدّله المستخدم.
            'max_limit',
            'min_limit_1',
            'min_limit_2',
            // نوع التسعير عند الانشاء فقط، فصنف الوزن وزن من اول استيراد، وتعديله يبقى.
            'pricing_type',
            'unit_id',
        ];

        $updates = implode(', ', [
            'slug = VALUES(slug)',
            'barcode = VALUES(barcode)',
            'discount = VALUES(discount)',
            'base_price = VALUES(base_price)',
            'sale_price = VALUES(sale_price)',
            'cost_price = VALUES(cost_price)',
            'wholesale_price = VALUES(wholesale_price)',
            // stock_quantity لا يُحدَّث عند اعادة الاستيراد: يملكه دفتر المخزون (StockLedgerService).
            'manage_stock = VALUES(manage_stock)',
            'low_stock_threshold = VALUES(low_stock_threshold)',
            'stock_status = VALUES(stock_status)',
            'allow_backorder = VALUES(allow_backorder)',
            'max_quantity_per_order = VALUES(max_quantity_per_order)',
            'discount_type = VALUES(discount_type)',
            'discount_value = VALUES(discount_value)',
            'discount_start_date = VALUES(discount_start_date)',
            'discount_end_date = VALUES(discount_end_date)',
            'min_quantity_discount = VALUES(min_quantity_discount)',
            // weight لا يُحدَّث: Prop1 ليس وزناً في اغلب الانواع (LegacyItemFields). وهذه الاعمدة
            // تُملأ اذا كانت فارغة فقط، فلا تمحو اعادة الاستيراد ما عدّله المستخدم.
            'weight_per_meter = COALESCE(weight_per_meter, VALUES(weight_per_meter))',
            '`length` = COALESCE(`length`, VALUES(`length`))',
            "shelf = COALESCE(NULLIF(shelf, ''), VALUES(shelf))",
            'unit_id = COALESCE(unit_id, VALUES(unit_id))',
            'status = VALUES(status)',
            'is_featured = VALUES(is_featured)',
            'is_digital = VALUES(is_digital)',
            'meta_data = VALUES(meta_data)',
            'updated_at = VALUES(updated_at)',
            'parent_id = VALUES(parent_id)',
            'brand_id = VALUES(brand_id)',
            'is_active = VALUES(is_active)',
            'deleted_at = NULL',
        ]);

        $rows = [];
        $processed = 0;

        foreach ($productOldIds as $oldId) {
            $item = $items[$this->legacyKey($oldId)];
            $cost = $this->decimalValue($item['ItemCost'] ?? null);
            $active = $this->isActive($item);
            $type = $this->nullableString($item['ItemType'] ?? null);
            $fields = LegacyItemFields::columns($type, $item);

            $rows[] = [
                $this->legacySlug('old-product', $oldId),
                LegacyItemFields::barcodes($item['Barcode'] ?? null, $type, $item)['primary'],
                $oldId,
                0,
                $cost ?? '0.00',
                null,
                $cost,
                null,
                0,
                0,
                $this->positiveInt($item['ItemMin1'] ?? null) ?? 10,
                'in_stock',
                0,
                null,
                'none',
                null,
                null,
                null,
                null,
                null,
                $fields['weight_per_meter'] ?? null,
                $fields['length'] ?? null,
                $fields['shelf'] ?? null,
                $active ? 2 : 1,
                0,
                0,
                $this->productMetaData($item),
                null,
                $now,
                $now,
                null,
                null,
                $active ? 1 : 0,
                $this->positiveInt($item['ItemMax'] ?? null),
                $this->positiveInt($item['ItemMin1'] ?? null),
                $this->positiveInt($item['ItemMin2'] ?? null),
                $this->mapPricingType($type),
                $units->findOrCreate($item['itemunit'] ?? null),
            ];

            if (count($rows) >= 500) {
                $this->bulkUpsert($pdo, $this->table('products'), $columns, $rows, $updates, 500);
                $processed += count($rows);
                $this->log($logger, "Upserted {$processed} products...");
                $rows = [];
            }
        }

        if ($rows) {
            $this->bulkUpsert($pdo, $this->table('products'), $columns, $rows, $updates, 500);
            $processed += count($rows);
            $this->log($logger, "Upserted {$processed} products...");
        }
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param array<int, string> $productOldIds
     * @param array<string, int> $productIdBySku
     * @param array<string, int> $stats
     */
    private function insertProductTranslations(PDO $pdo, array $items, array $productOldIds, array $productIdBySku, array &$stats, string $now): void
    {
        $columns = ['product_id', 'locale', 'name', 'short_description', 'description', 'created_at', 'updated_at'];
        $rows = [];
        $inserted = 0;

        foreach ($productOldIds as $oldId) {
            $productId = $productIdBySku[$oldId] ?? null;
            if (! $productId) {
                continue;
            }

            $item = $items[$this->legacyKey($oldId)];
            $rows[] = [
                $productId,
                'ar',
                $this->translationName($item, $oldId),
                null,
                $this->nullableString($item['ItemNote'] ?? null),
                $now,
                $now,
            ];

            if (count($rows) >= 1000) {
                $this->bulkInsert($pdo, $this->table('product_translations'), $columns, $rows);
                $inserted += count($rows);
                $rows = [];
            }
        }

        if ($rows) {
            $this->bulkInsert($pdo, $this->table('product_translations'), $columns, $rows);
            $inserted += count($rows);
        }

        $stats['product_translations_created'] = $inserted;
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param array<int, string> $productOldIds
     * @param array<string, int> $productIdBySku
     * @param array<string, string> $categoryOldIds
     * @param array<string, int> $categoryIdByOldId
     * @param array<string, int> $stats
     */
    private function insertProductCategoryLinks(
        PDO $pdo,
        array $items,
        array $productOldIds,
        array $productIdBySku,
        array $categoryOldIds,
        array $categoryIdByOldId,
        array &$stats,
        string $now
    ): void {
        $columns = ['product_id', 'category_id', 'created_at', 'updated_at'];
        $rows = [];
        $inserted = 0;

        foreach ($productOldIds as $oldId) {
            $productId = $productIdBySku[$oldId] ?? null;
            if (! $productId) {
                continue;
            }

            $item = $items[$this->legacyKey($oldId)];

            // الصنف الرئيسي في تصنيفه التوأم مع مقاطعه، كما ينشئه MainProductLinker.
            $categoryId = $this->isMainItemRow($item) && isset($categoryOldIds[$this->legacyKey($oldId)])
                ? ($categoryIdByOldId[$oldId] ?? null)
                : $this->nearestCategoryIdFromMap(
                    $this->normalizeLegacyId($item['ItemParent'] ?? null),
                    $items,
                    $categoryOldIds,
                    $categoryIdByOldId
                );
            if (! $categoryId) {
                continue;
            }

            $rows[] = [$productId, $categoryId, $now, $now];

            if (count($rows) >= 1000) {
                $this->bulkInsert($pdo, $this->table('products_categories'), $columns, $rows);
                $inserted += count($rows);
                $rows = [];
            }
        }

        if ($rows) {
            $this->bulkInsert($pdo, $this->table('products_categories'), $columns, $rows);
            $inserted += count($rows);
        }

        $stats['product_category_links_created'] = $inserted;
    }

    /**
     * الباركودات بعد الاول في الصنف القديم، بكميات عبواتها.
     *
     * @param array<string, array<string, mixed>> $items
     * @param array<int, string> $productOldIds
     * @param array<string, int> $productIdBySku
     * @param array<string, int> $stats
     */
    private function insertLegacyBarcodes(array $items, array $productOldIds, array $productIdBySku, array &$stats): void
    {
        $writer = app(LegacyBarcodeWriter::class);

        foreach (array_chunk($productOldIds, 1000) as $chunk) {
            $byProduct = [];

            foreach ($chunk as $oldId) {
                $item = $items[$this->legacyKey($oldId)];
                $productId = $productIdBySku[$oldId] ?? null;
                $parsed = LegacyItemFields::barcodes($item['Barcode'] ?? null, $this->nullableString($item['ItemType'] ?? null), $item);

                if ($parsed['primary'] !== null && $parsed['primary_pack_quantity'] !== '1.000000') {
                    $stats['first_barcode_pack_quantity_ignored']++;
                }

                if ($productId && $parsed['secondary'] !== []) {
                    $byProduct[$productId] = $parsed['secondary'];
                }
            }

            $stats['barcode_rows_created'] += $writer->insert($writer->plan($byProduct));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param array<int, string> $productOldIds
     * @param array<string, int> $productIdBySku
     * @param array<string, int> $stats
     */
    private function insertEnglishNames(array $items, array $productOldIds, array $productIdBySku, array &$stats): void
    {
        $writer = app(LegacyEnglishNameWriter::class);

        foreach (array_chunk($productOldIds, 1000) as $chunk) {
            $names = [];

            foreach ($chunk as $oldId) {
                $productId = $productIdBySku[$oldId] ?? null;
                $name = LegacyItemFields::englishName($items[$this->legacyKey($oldId)]);

                if ($productId && $name !== null) {
                    $names[$productId] = $name;
                }
            }

            $stats['english_names_created'] += $writer->insert($writer->plan($names));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param array<string, string> $categoryOldIds
     * @param array<string, int> $categoryIdByOldId
     */
    private function nearestCategoryIdFromMap(string $oldParentId, array $items, array $categoryOldIds, array $categoryIdByOldId): ?int
    {
        $current = $oldParentId;
        $seen = [];

        while ($current !== '' && $current !== '0' && isset($items[$this->legacyKey($current)]) && ! isset($seen[$current])) {
            $seen[$current] = true;

            if (isset($categoryOldIds[$this->legacyKey($current)])) {
                return $categoryIdByOldId[$current] ?? null;
            }

            $current = $this->normalizeLegacyId($items[$this->legacyKey($current)]['ItemParent'] ?? null);
        }

        return null;
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<int, mixed>> $rows
     */
    private function bulkUpsert(PDO $pdo, string $table, array $columns, array $rows, string $updates, int $batchSize): void
    {
        foreach (array_chunk($rows, $batchSize) as $chunk) {
            if (! $chunk) {
                continue;
            }

            $columnSql = implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns));
            $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $placeholders = implode(', ', array_fill(0, count($chunk), $rowPlaceholder));
            $sql = 'INSERT INTO ' . $this->quoteIdentifier($table) . " ({$columnSql}) VALUES {$placeholders} ON DUPLICATE KEY UPDATE {$updates}";

            $values = [];
            foreach ($chunk as $row) {
                array_push($values, ...$row);
            }

            $pdo->prepare($sql)->execute($values);
        }
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<int, mixed>> $rows
     */
    private function bulkInsert(PDO $pdo, string $table, array $columns, array $rows): void
    {
        if (! $rows) {
            return;
        }

        $columnSql = implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns));
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($rows), $rowPlaceholder));
        $sql = 'INSERT INTO ' . $this->quoteIdentifier($table) . " ({$columnSql}) VALUES {$placeholders}";

        $values = [];
        foreach ($rows as $row) {
            array_push($values, ...$row);
        }

        $pdo->prepare($sql)->execute($values);
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return array<string, bool>
     */
    private function fetchExistingValues(PDO $pdo, string $table, string $column, array $values): array
    {
        $existing = [];

        foreach (array_chunk(array_values($values), 1000) as $chunk) {
            if (! $chunk) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare('SELECT ' . $this->quoteIdentifier($column) . ' FROM ' . $this->quoteIdentifier($table) . ' WHERE ' . $this->quoteIdentifier($column) . " IN ({$placeholders})");
            $stmt->execute($chunk);

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
                $existing[(string) $value] = true;
            }
        }

        return $existing;
    }

    /**
     * @param array<int, string> $oldIds
     *
     * @return array<string, int>
     */
    private function fetchIdMapByLegacySlug(PDO $pdo, string $table, string $prefix, array $oldIds): array
    {
        $slugs = array_map(fn (string $oldId): string => $this->legacySlug($prefix, $oldId), $oldIds);
        $rows = $this->fetchRowsByColumn($pdo, $table, 'slug', $slugs, ['id', 'slug']);
        $idBySlug = [];
        $map = [];

        foreach ($rows as $row) {
            $idBySlug[(string) $row['slug']] = (int) $row['id'];
        }

        foreach ($oldIds as $oldId) {
            $slug = $this->legacySlug($prefix, $oldId);
            if (isset($idBySlug[$slug])) {
                $map[$oldId] = $idBySlug[$slug];
            }
        }

        return $map;
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return array<string, int>
     */
    private function fetchIdMapByColumn(PDO $pdo, string $table, string $column, array $values): array
    {
        $rows = $this->fetchRowsByColumn($pdo, $table, $column, $values, ['id', $column]);
        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row[$column]] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * @param array<int, mixed> $values
     * @param array<int, string> $selectColumns
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchRowsByColumn(PDO $pdo, string $table, string $column, array $values, array $selectColumns): array
    {
        $rows = [];
        $selectSql = implode(', ', array_map(fn (string $select): string => $this->quoteIdentifier($select), $selectColumns));

        foreach (array_chunk(array_values($values), 1000) as $chunk) {
            if (! $chunk) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("SELECT {$selectSql} FROM " . $this->quoteIdentifier($table) . ' WHERE ' . $this->quoteIdentifier($column) . " IN ({$placeholders})");
            $stmt->execute($chunk);
            array_push($rows, ...$stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        return $rows;
    }

    /**
     * @param array<int, mixed> $ids
     */
    private function deleteByIds(PDO $pdo, string $table, string $column, array $ids, ?string $extraWhere = null): int
    {
        $deleted = 0;

        foreach (array_chunk(array_values($ids), 1000) as $chunk) {
            if (! $chunk) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $where = $this->quoteIdentifier($column) . " IN ({$placeholders})";
            if ($extraWhere) {
                $where .= " AND {$extraWhere}";
            }

            $stmt = $pdo->prepare('DELETE FROM ' . $this->quoteIdentifier($table) . " WHERE {$where}");
            $stmt->execute($chunk);
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }

    /**
     * @param array<int, mixed> $ids
     */
    private function countExistingByIds(PDO $pdo, string $table, string $column, array $ids, ?string $extraWhere = null): int
    {
        $count = 0;

        foreach (array_chunk(array_values($ids), 1000) as $chunk) {
            if (! $chunk) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $where = $this->quoteIdentifier($column) . " IN ({$placeholders})";
            if ($extraWhere) {
                $where .= " AND {$extraWhere}";
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table) . " WHERE {$where}");
            $stmt->execute($chunk);
            $count += (int) $stmt->fetchColumn();
        }

        return $count;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readOldItems(string $file): array
    {
        $handle = fopen($file, 'rb');
        if (! $handle) {
            throw new RuntimeException("Unable to open SQL file: {$file}");
        }

        $items = [];
        $inInsert = false;
        $lineNo = 0;

        while (($line = fgets($handle)) !== false) {
            $lineNo++;
            $trimmed = trim($line);

            if (! $inInsert) {
                if (preg_match('/^INSERT\s+INTO\s+`?items`?/i', $trimmed)) {
                    $inInsert = true;
                }

                continue;
            }

            if ($trimmed === '') {
                continue;
            }

            if ($trimmed[0] === '(') {
                $tuple = rtrim($trimmed, ",;\r\n\t ");
                $values = $this->parseSqlTuple($tuple, $lineNo);

                if (count($values) !== 24) {
                    throw new RuntimeException('Unexpected value count at line ' . $lineNo . ': ' . count($values));
                }

                $row = $this->mapOldItem($values);
                $id = $this->normalizeLegacyId($row['ItemId']);

                if ($id !== '') {
                    $row['ItemId'] = $id;
                    $items[$this->legacyKey($id)] = $row;
                }
            }

            if (str_ends_with($trimmed, ';')) {
                $inInsert = false;
            }
        }

        fclose($handle);

        return $items;
    }

    /**
     * @return array<int, mixed>
     */
    private function parseSqlTuple(string $tuple, int $lineNo): array
    {
        $length = strlen($tuple);
        if ($length < 2 || $tuple[0] !== '(' || $tuple[$length - 1] !== ')') {
            throw new RuntimeException("Invalid tuple at line {$lineNo}");
        }

        $values = [];
        $i = 1;

        while ($i < $length - 1) {
            while ($i < $length - 1 && ctype_space($tuple[$i])) {
                $i++;
            }

            if ($i >= $length - 1) {
                break;
            }

            if ($tuple[$i] === "'") {
                [$value, $i] = $this->parseSqlString($tuple, $i + 1);
            } else {
                $start = $i;
                while ($i < $length && $tuple[$i] !== ',' && $tuple[$i] !== ')') {
                    $i++;
                }

                $raw = trim(substr($tuple, $start, $i - $start));
                $value = strcasecmp($raw, 'NULL') === 0 ? null : $raw;
            }

            $values[] = $value;

            while ($i < $length - 1 && ctype_space($tuple[$i])) {
                $i++;
            }

            if ($i < $length - 1 && $tuple[$i] === ',') {
                $i++;
            }
        }

        return $values;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function parseSqlString(string $source, int $i): array
    {
        $value = '';
        $length = strlen($source);

        while ($i < $length) {
            $char = $source[$i];

            if ($char === "\\") {
                $next = $source[$i + 1] ?? '';
                $value .= match ($next) {
                    '0' => "\0",
                    'b' => "\b",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'Z' => chr(26),
                    default => $next,
                };
                $i += 2;
                continue;
            }

            if ($char === "'") {
                if (($source[$i + 1] ?? '') === "'") {
                    $value .= "'";
                    $i += 2;
                    continue;
                }

                return [$value, $i + 1];
            }

            $value .= $char;
            $i++;
        }

        return [$value, $i];
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function mapOldItem(array $values): array
    {
        return array_combine(self::LEGACY_COLUMNS, $values);
    }

    /**
     * قيمة رقمية من ملفات إتقان بصيغتها النصية كما في ملف SQL المصدَّر، فيبقى منطق التحويل واحداً.
     */
    private function scalarString(mixed $value): string
    {
        if (is_float($value)) {
            $text = (string) $value;

            return str_contains($text, 'E') ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : $text;
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function productMetaData(array $item): string
    {
        $props = [];
        foreach (['Prop1', 'Prop2', 'Prop3', 'Prop4', 'Prop5', 'Prop6', 'prop7', 'prop8', 'prop9'] as $key) {
            $props[$key] = $this->nullableString($item[$key] ?? null);
        }

        $meta = [
            'legacy_item_id' => $this->normalizeLegacyId($item['ItemId'] ?? null),
            'legacy_item_type' => $this->nullableString($item['ItemType'] ?? null),
            'legacy_parent_id' => $this->nullableString($item['ItemParent'] ?? null),
            'legacy_barcode' => $this->nullableString($item['Barcode'] ?? null),
            'unit' => $this->nullableString($item['itemunit'] ?? null),
            'default_store' => $this->intOrNull($item['ItemDefStore'] ?? null),
            'item_max' => $this->intOrNull($item['ItemMax'] ?? null),
            'item_min1' => $this->intOrNull($item['ItemMin1'] ?? null),
            'item_min2' => $this->intOrNull($item['ItemMin2'] ?? null),
            'exp_days' => $this->intOrNull($item['ExpDays'] ?? null),
            'change_range' => $this->intOrNull($item['ChRange'] ?? null),
            'props' => $props,
        ];

        return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function translationName(array $item, string $fallbackId): string
    {
        $name = trim((string) ($item['ItemString'] ?? ''));
        if ($name === '') {
            $name = 'Item ' . $fallbackId;
        }

        return $this->limitString($name, 255);
    }

    private function legacySlug(string $prefix, string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]+/', '-', $id);
        $clean = trim((string) $clean, '-');

        return strtolower($prefix . '-' . ($clean !== '' ? $clean : sha1($id)));
    }

    private function normalizeLegacyId($value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function legacyKey($value): string
    {
        return 'legacy:' . $this->normalizeLegacyId($value);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isCategoryRow(array $item): bool
    {
        return trim((string) ($item['ItemType'] ?? '')) === 'c_cont';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isMainItemRow(array $item): bool
    {
        return trim((string) ($item['ItemType'] ?? '')) === 'a_al';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isActive(array $item): bool
    {
        return strtoupper(trim((string) ($item['isActive'] ?? ''))) === 'Y';
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function limitedNullableString($value, int $limit): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : $this->limitString($value, $limit);
    }

    private function limitString(string $value, int $limit): string
    {
        return mb_strlen($value, 'UTF-8') > $limit ? mb_substr($value, 0, $limit, 'UTF-8') : $value;
    }

    private function decimalValue($value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function intOrNull($value): ?int
    {
        $value = $this->nullableString($value);

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    private function positiveInt($value): ?int
    {
        $value = $this->intOrNull($value);

        return $value !== null && $value > 0 ? $value : null;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function table(string $name): string
    {
        return config('items.table_prefix', 'items_') . $name;
    }

    private function tableSql(string $name): string
    {
        return $this->quoteIdentifier($this->table($name));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function log(?callable $logger, string $message): void
    {
        if ($logger) {
            $logger($message);
        }
    }
}
