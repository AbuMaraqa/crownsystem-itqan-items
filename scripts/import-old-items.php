<?php

declare(strict_types=1);

ini_set('memory_limit', '1024M');
set_time_limit(0);

$root = dirname(__DIR__);
$defaultFile = 'C:\\Users\\Mohamad Maraqa\\Downloads\\items (1).sql';
$options = getopt('', ['file:', 'dry-run', 'all-nodes-as-products']);
$file = $options['file'] ?? firstPositionalArg($argv) ?? $defaultFile;
$dryRun = array_key_exists('dry-run', $options);
$allNodesAsProducts = array_key_exists('all-nodes-as-products', $options);

if (!is_file($file)) {
    fail("SQL file not found: {$file}");
}

$env = readEnvFile($root . DIRECTORY_SEPARATOR . '.env');
$pdo = connect($env);
$now = date('Y-m-d H:i:s');

$requiredTables = [
    'items_products',
    'items_product_translations',
    'items_categories',
    'items_category_translations',
    'items_products_categories',
];

foreach ($requiredTables as $table) {
    if (!tableExists($pdo, $table)) {
        fail("Missing table: {$table}");
    }
}

echo "Reading old items SQL: {$file}" . PHP_EOL;
$items = readOldItems($file);

if (!$items) {
    fail('No rows were found in the old `items` dump.');
}

$childrenByParent = [];
$parentIdsWithChildren = [];
$inactiveRows = 0;
$missingNames = 0;

foreach ($items as $item) {
    $id = normalizeLegacyId($item['ItemId'] ?? null);

    if (!isActive($item)) {
        $inactiveRows++;
    }

    if (trim((string) ($item['ItemString'] ?? '')) === '') {
        $missingNames++;
    }

    $parent = normalizeLegacyId($item['ItemParent'] ?? null);
    if ($parent !== '' && $parent !== $id && isset($items[legacyKey($parent)])) {
        $parentKey = legacyKey($parent);
        $childrenByParent[$parentKey][] = $id;
        $parentIdsWithChildren[$parentKey] = $parent;
    }
}

$categoryOldIds = [];
foreach ($parentIdsWithChildren as $parentKey => $parent) {
    if ($parent !== '0' && isset($items[$parentKey])) {
        $categoryOldIds[$parentKey] = $parent;
    }
}

$productOldIds = [];
foreach ($items as $item) {
    $id = normalizeLegacyId($item['ItemId'] ?? null);

    if ($id === '0') {
        continue;
    }

    if ($allNodesAsProducts || !isset($categoryOldIds[legacyKey($id)])) {
        $productOldIds[] = $id;
    }
}

$current = [
    'products' => (int) $pdo->query('SELECT COUNT(*) FROM items_products')->fetchColumn(),
    'categories' => (int) $pdo->query('SELECT COUNT(*) FROM items_categories')->fetchColumn(),
];

echo 'Old rows: ' . count($items) . PHP_EOL;
echo 'Old category nodes: ' . count($categoryOldIds) . PHP_EOL;
echo 'Old product rows selected: ' . count($productOldIds) . PHP_EOL;
echo 'Inactive old rows: ' . $inactiveRows . PHP_EOL;
echo 'Rows with missing names: ' . $missingNames . PHP_EOL;
echo 'Current DB products: ' . $current['products'] . PHP_EOL;
echo 'Current DB categories: ' . $current['categories'] . PHP_EOL;

if ($dryRun) {
    echo 'Dry run only. No database changes were made.' . PHP_EOL;
    exit(0);
}

$stats = bulkImport($pdo, $items, $categoryOldIds, $productOldIds, $now);

echo 'Import complete.' . PHP_EOL;
foreach ($stats as $key => $value) {
    echo $key . '=' . $value . PHP_EOL;
}

exit(0);

function bulkImport(PDO $pdo, array $items, array $categoryOldIds, array $productOldIds, string $now): array
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
    ];

    $categoryOldIdList = array_values($categoryOldIds);
    $existingCategorySlugs = fetchExistingValues(
        $pdo,
        'items_categories',
        'slug',
        array_map(fn(string $oldId): string => legacySlug('old-cat', $oldId), $categoryOldIdList)
    );
    $existingProductSkus = fetchExistingValues($pdo, 'items_products', 'sku', $productOldIds);

    $stats['categories_updated'] = count($existingCategorySlugs);
    $stats['categories_created'] = count($categoryOldIdList) - $stats['categories_updated'];
    $stats['products_updated'] = count($existingProductSkus);
    $stats['products_created'] = count($productOldIds) - $stats['products_updated'];

    $pdo->beginTransaction();

    try {
        upsertCategories($pdo, $items, $categoryOldIdList, $now);
        $categoryIdByOldId = fetchIdMapByLegacySlug($pdo, 'items_categories', 'old-cat', $categoryOldIdList);
        updateCategoryParents($pdo, $items, $categoryOldIdList, $categoryIdByOldId, $now);
        upsertCategoryTranslations($pdo, $items, $categoryOldIdList, $categoryIdByOldId, $stats, $now);

        upsertProducts($pdo, $items, $productOldIds, $now);
        $productIdBySku = fetchIdMapByColumn($pdo, 'items_products', 'sku', $productOldIds);

        $productIds = array_values($productIdBySku);
        $stats['product_translations_replaced'] = deleteByIds($pdo, 'items_product_translations', 'product_id', $productIds, "locale = 'ar'");
        insertProductTranslations($pdo, $items, $productOldIds, $productIdBySku, $stats, $now);

        $stats['product_category_links_replaced'] = deleteByIds($pdo, 'items_products_categories', 'product_id', $productIds);
        insertProductCategoryLinks($pdo, $items, $productOldIds, $productIdBySku, $categoryOldIds, $categoryIdByOldId, $stats, $now);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $stats;
}

function upsertCategories(PDO $pdo, array $items, array $categoryOldIdList, string $now): void
{
    $columns = ['slug', 'status', 'sort_order', 'parent_id', 'created_by', 'created_at', 'updated_at'];
    $rows = [];

    foreach ($categoryOldIdList as $oldId) {
        $item = $items[legacyKey($oldId)];
        $rows[] = [
            legacySlug('old-cat', $oldId),
            isActive($item) ? 1 : 0,
            0,
            null,
            null,
            $now,
            $now,
        ];
    }

    bulkUpsert(
        $pdo,
        'items_categories',
        $columns,
        $rows,
        'status = VALUES(status), sort_order = VALUES(sort_order), updated_at = VALUES(updated_at), deleted_at = NULL',
        500
    );
}

function updateCategoryParents(PDO $pdo, array $items, array $categoryOldIdList, array $categoryIdByOldId, string $now): void
{
    $updates = [];

    foreach ($categoryOldIdList as $oldId) {
        $categoryId = $categoryIdByOldId[$oldId] ?? null;
        if (!$categoryId) {
            continue;
        }

        $parentOldId = normalizeLegacyId($items[legacyKey($oldId)]['ItemParent'] ?? null);
        $parentId = $categoryIdByOldId[$parentOldId] ?? null;
        if ($parentId === $categoryId) {
            $parentId = null;
        }

        $updates[] = [$categoryId, $parentId];
    }

    foreach (array_chunk($updates, 500) as $chunk) {
        if (!$chunk) {
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

        $pdo->prepare("UPDATE items_categories SET parent_id = {$caseSql}, updated_at = ? WHERE id IN ({$placeholders})")
            ->execute($params);
    }
}

function upsertCategoryTranslations(PDO $pdo, array $items, array $categoryOldIdList, array $categoryIdByOldId, array &$stats, string $now): void
{
    $columns = ['category_id', 'locale', 'name', 'description', 'created_at', 'updated_at'];
    $rows = [];
    $existing = 0;

    foreach ($categoryOldIdList as $oldId) {
        $categoryId = $categoryIdByOldId[$oldId] ?? null;
        if (!$categoryId) {
            continue;
        }

        $item = $items[legacyKey($oldId)];
        $rows[] = [
            $categoryId,
            'ar',
            translationName($item, $oldId),
            nullableString($item['ItemNote'] ?? null),
            $now,
            $now,
        ];
    }

    foreach (array_chunk(array_column($rows, 0), 1000) as $ids) {
        $existing += countExistingByIds($pdo, 'items_category_translations', 'category_id', $ids, "locale = 'ar'");
    }

    bulkUpsert(
        $pdo,
        'items_category_translations',
        $columns,
        $rows,
        'name = VALUES(name), description = VALUES(description), updated_at = VALUES(updated_at), deleted_at = NULL',
        500
    );

    $stats['category_translations_updated'] = $existing;
    $stats['category_translations_created'] = count($rows) - $existing;
}

function upsertProducts(PDO $pdo, array $items, array $productOldIds, string $now): void
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
    ];

    $updates = implode(', ', [
        'slug = VALUES(slug)',
        'barcode = VALUES(barcode)',
        'discount = VALUES(discount)',
        'base_price = VALUES(base_price)',
        'sale_price = VALUES(sale_price)',
        'cost_price = VALUES(cost_price)',
        'wholesale_price = VALUES(wholesale_price)',
        'stock_quantity = VALUES(stock_quantity)',
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
        'weight = VALUES(weight)',
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
        $item = $items[legacyKey($oldId)];
        $cost = decimalValue($item['ItemCost'] ?? null);
        $active = isActive($item);

        $rows[] = [
            legacySlug('old-product', $oldId),
            limitedNullableString($item['Barcode'] ?? null, 255),
            $oldId,
            0,
            $cost ?? '0.00',
            null,
            $cost,
            null,
            null,
            0,
            positiveInt($item['ItemMin1'] ?? null) ?? 10,
            'in_stock',
            0,
            null,
            'none',
            null,
            null,
            null,
            null,
            decimalValue($item['Prop1'] ?? null),
            $active ? 2 : 1,
            0,
            0,
            productMetaData($item),
            null,
            $now,
            $now,
            null,
            null,
            $active ? 1 : 0,
        ];

        if (count($rows) >= 500) {
            bulkUpsert($pdo, 'items_products', $columns, $rows, $updates, 500);
            $processed += count($rows);
            echo "Upserted {$processed} products..." . PHP_EOL;
            $rows = [];
        }
    }

    if ($rows) {
        bulkUpsert($pdo, 'items_products', $columns, $rows, $updates, 500);
        $processed += count($rows);
        echo "Upserted {$processed} products..." . PHP_EOL;
    }
}

function insertProductTranslations(PDO $pdo, array $items, array $productOldIds, array $productIdBySku, array &$stats, string $now): void
{
    $columns = ['product_id', 'locale', 'name', 'short_description', 'description', 'created_at', 'updated_at'];
    $rows = [];
    $inserted = 0;

    foreach ($productOldIds as $oldId) {
        $productId = $productIdBySku[$oldId] ?? null;
        if (!$productId) {
            continue;
        }

        $item = $items[legacyKey($oldId)];
        $rows[] = [
            $productId,
            'ar',
            translationName($item, $oldId),
            null,
            nullableString($item['ItemNote'] ?? null),
            $now,
            $now,
        ];

        if (count($rows) >= 1000) {
            bulkInsert($pdo, 'items_product_translations', $columns, $rows);
            $inserted += count($rows);
            $rows = [];
        }
    }

    if ($rows) {
        bulkInsert($pdo, 'items_product_translations', $columns, $rows);
        $inserted += count($rows);
    }

    $stats['product_translations_created'] = $inserted;
}

function insertProductCategoryLinks(
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
        if (!$productId) {
            continue;
        }

        $categoryId = nearestCategoryIdFromMap(normalizeLegacyId($items[legacyKey($oldId)]['ItemParent'] ?? null), $items, $categoryOldIds, $categoryIdByOldId);
        if (!$categoryId) {
            continue;
        }

        $rows[] = [$productId, $categoryId, $now, $now];

        if (count($rows) >= 1000) {
            bulkInsert($pdo, 'items_products_categories', $columns, $rows);
            $inserted += count($rows);
            $rows = [];
        }
    }

    if ($rows) {
        bulkInsert($pdo, 'items_products_categories', $columns, $rows);
        $inserted += count($rows);
    }

    $stats['product_category_links_created'] = $inserted;
}

function nearestCategoryIdFromMap(string $oldParentId, array $items, array $categoryOldIds, array $categoryIdByOldId): ?int
{
    $current = $oldParentId;
    $seen = [];

    while ($current !== '' && $current !== '0' && isset($items[legacyKey($current)]) && !isset($seen[$current])) {
        $seen[$current] = true;

        if (isset($categoryOldIds[legacyKey($current)])) {
            return $categoryIdByOldId[$current] ?? null;
        }

        $current = normalizeLegacyId($items[legacyKey($current)]['ItemParent'] ?? null);
    }

    return null;
}

function bulkUpsert(PDO $pdo, string $table, array $columns, array $rows, string $updates, int $batchSize): void
{
    foreach (array_chunk($rows, $batchSize) as $chunk) {
        if (!$chunk) {
            continue;
        }

        $columnSql = implode(', ', array_map(fn(string $column): string => "`{$column}`", $columns));
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($chunk), $rowPlaceholder));
        $sql = "INSERT INTO `{$table}` ({$columnSql}) VALUES {$placeholders} ON DUPLICATE KEY UPDATE {$updates}";

        $values = [];
        foreach ($chunk as $row) {
            array_push($values, ...$row);
        }

        $pdo->prepare($sql)->execute($values);
    }
}

function bulkInsert(PDO $pdo, string $table, array $columns, array $rows): void
{
    if (!$rows) {
        return;
    }

    $columnSql = implode(', ', array_map(fn(string $column): string => "`{$column}`", $columns));
    $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $placeholders = implode(', ', array_fill(0, count($rows), $rowPlaceholder));
    $sql = "INSERT INTO `{$table}` ({$columnSql}) VALUES {$placeholders}";

    $values = [];
    foreach ($rows as $row) {
        array_push($values, ...$row);
    }

    $pdo->prepare($sql)->execute($values);
}

function fetchExistingValues(PDO $pdo, string $table, string $column, array $values): array
{
    $existing = [];

    foreach (array_chunk(array_values($values), 1000) as $chunk) {
        if (!$chunk) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("SELECT `{$column}` FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
        $stmt->execute($chunk);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $existing[(string) $value] = true;
        }
    }

    return $existing;
}

function fetchIdMapByLegacySlug(PDO $pdo, string $table, string $prefix, array $oldIds): array
{
    $slugs = array_map(fn(string $oldId): string => legacySlug($prefix, $oldId), $oldIds);
    $rows = fetchRowsByColumn($pdo, $table, 'slug', $slugs, ['id', 'slug']);
    $idBySlug = [];
    $map = [];

    foreach ($rows as $row) {
        $idBySlug[(string) $row['slug']] = (int) $row['id'];
    }

    foreach ($oldIds as $oldId) {
        $slug = legacySlug($prefix, $oldId);
        if (isset($idBySlug[$slug])) {
            $map[$oldId] = $idBySlug[$slug];
        }
    }

    return $map;
}

function fetchIdMapByColumn(PDO $pdo, string $table, string $column, array $values): array
{
    $rows = fetchRowsByColumn($pdo, $table, $column, $values, ['id', $column]);
    $map = [];

    foreach ($rows as $row) {
        $map[(string) $row[$column]] = (int) $row['id'];
    }

    return $map;
}

function fetchRowsByColumn(PDO $pdo, string $table, string $column, array $values, array $selectColumns): array
{
    $rows = [];
    $selectSql = implode(', ', array_map(fn(string $select): string => "`{$select}`", $selectColumns));

    foreach (array_chunk(array_values($values), 1000) as $chunk) {
        if (!$chunk) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("SELECT {$selectSql} FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
        $stmt->execute($chunk);
        array_push($rows, ...$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    return $rows;
}

function deleteByIds(PDO $pdo, string $table, string $column, array $ids, ?string $extraWhere = null): int
{
    $deleted = 0;

    foreach (array_chunk(array_values($ids), 1000) as $chunk) {
        if (!$chunk) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $where = "`{$column}` IN ({$placeholders})";
        if ($extraWhere) {
            $where .= " AND {$extraWhere}";
        }

        $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE {$where}");
        $stmt->execute($chunk);
        $deleted += $stmt->rowCount();
    }

    return $deleted;
}

function countExistingByIds(PDO $pdo, string $table, string $column, array $ids, ?string $extraWhere = null): int
{
    $count = 0;

    foreach (array_chunk(array_values($ids), 1000) as $chunk) {
        if (!$chunk) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $where = "`{$column}` IN ({$placeholders})";
        if ($extraWhere) {
            $where .= " AND {$extraWhere}";
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
        $stmt->execute($chunk);
        $count += (int) $stmt->fetchColumn();
    }

    return $count;
}

function firstPositionalArg(array $argv): ?string
{
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            return $arg;
        }
    }

    return null;
}

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function readEnvFile(string $path): array
{
    $values = [];
    if (!is_file($path)) {
        return $values;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $values[trim($key)] = $value;
    }

    return $values;
}

function connect(array $env): PDO
{
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = $env['DB_PORT'] ?? '3306';
    $database = $env['DB_DATABASE'] ?? 'crownsystem';
    $username = $env['DB_USERNAME'] ?? 'root';
    $password = $env['DB_PASSWORD'] ?? '';
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
    ]);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);

    return (bool) $stmt->fetchColumn();
}

function readOldItems(string $file): array
{
    $handle = fopen($file, 'rb');
    if (!$handle) {
        fail("Unable to open SQL file: {$file}");
    }

    $items = [];
    $inInsert = false;
    $lineNo = 0;

    while (($line = fgets($handle)) !== false) {
        $lineNo++;
        $trimmed = trim($line);

        if (!$inInsert) {
            if (stripos($trimmed, 'INSERT INTO `items`') === 0) {
                $inInsert = true;
            }

            continue;
        }

        if ($trimmed === '') {
            continue;
        }

        if ($trimmed[0] === '(') {
            $tuple = rtrim($trimmed, ",;\r\n\t ");
            $values = parseSqlTuple($tuple, $lineNo);

            if (count($values) !== 24) {
                fail("Unexpected value count at line {$lineNo}: " . count($values));
            }

            $row = mapOldItem($values);
            $id = normalizeLegacyId($row['ItemId']);

            if ($id !== '') {
                $row['ItemId'] = $id;
                $items[legacyKey($id)] = $row;
            }
        }

        if (str_ends_with($trimmed, ';')) {
            $inInsert = false;
        }
    }

    fclose($handle);

    return $items;
}

function parseSqlTuple(string $tuple, int $lineNo): array
{
    $length = strlen($tuple);
    if ($length < 2 || $tuple[0] !== '(' || $tuple[$length - 1] !== ')') {
        fail("Invalid tuple at line {$lineNo}");
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
            [$value, $i] = parseSqlString($tuple, $i + 1);
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

function parseSqlString(string $source, int $i): array
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

function mapOldItem(array $values): array
{
    $keys = [
        'ItemId',
        'ItemString',
        'Barcode',
        'ItemType',
        'ItemCost',
        'itemunit',
        'ItemParent',
        'ItemDefStore',
        'ItemMax',
        'ItemMin1',
        'ItemMin2',
        'ExpDays',
        'ItemNote',
        'ChRange',
        'Prop1',
        'Prop2',
        'Prop3',
        'Prop4',
        'Prop5',
        'Prop6',
        'prop7',
        'prop8',
        'prop9',
        'isActive',
    ];

    return array_combine($keys, $values);
}

function productMetaData(array $item): string
{
    $props = [];
    foreach (['Prop1', 'Prop2', 'Prop3', 'Prop4', 'Prop5', 'Prop6', 'prop7', 'prop8', 'prop9'] as $key) {
        $props[$key] = nullableString($item[$key] ?? null);
    }

    $meta = [
        'legacy_item_id' => normalizeLegacyId($item['ItemId'] ?? null),
        'legacy_item_type' => nullableString($item['ItemType'] ?? null),
        'legacy_parent_id' => nullableString($item['ItemParent'] ?? null),
        'legacy_barcode' => nullableString($item['Barcode'] ?? null),
        'unit' => nullableString($item['itemunit'] ?? null),
        'default_store' => intOrNull($item['ItemDefStore'] ?? null),
        'item_max' => intOrNull($item['ItemMax'] ?? null),
        'item_min1' => intOrNull($item['ItemMin1'] ?? null),
        'item_min2' => intOrNull($item['ItemMin2'] ?? null),
        'exp_days' => intOrNull($item['ExpDays'] ?? null),
        'change_range' => intOrNull($item['ChRange'] ?? null),
        'props' => $props,
    ];

    return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function translationName(array $item, string $fallbackId): string
{
    $name = trim((string) ($item['ItemString'] ?? ''));
    if ($name === '') {
        $name = 'Item ' . $fallbackId;
    }

    return limitString($name, 255);
}

function legacySlug(string $prefix, string $id): string
{
    $clean = preg_replace('/[^A-Za-z0-9]+/', '-', $id);
    $clean = trim((string) $clean, '-');

    return strtolower($prefix . '-' . ($clean !== '' ? $clean : sha1($id)));
}

function normalizeLegacyId($value): string
{
    return trim((string) ($value ?? ''));
}

function legacyKey($value): string
{
    return 'legacy:' . normalizeLegacyId($value);
}

function isActive(array $item): bool
{
    return strtoupper(trim((string) ($item['isActive'] ?? ''))) === 'Y';
}

function nullableString($value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);

    return $value === '' ? null : $value;
}

function limitedNullableString($value, int $limit): ?string
{
    $value = nullableString($value);

    return $value === null ? null : limitString($value, $limit);
}

function limitString(string $value, int $limit): string
{
    return mb_strlen($value, 'UTF-8') > $limit ? mb_substr($value, 0, $limit, 'UTF-8') : $value;
}

function decimalValue($value): ?string
{
    $value = nullableString($value);
    if ($value === null || !is_numeric($value)) {
        return null;
    }

    return number_format((float) $value, 2, '.', '');
}

function intOrNull($value): ?int
{
    $value = nullableString($value);

    return $value !== null && is_numeric($value) ? (int) $value : null;
}

function positiveInt($value): ?int
{
    $value = intOrNull($value);

    return $value !== null && $value > 0 ? $value : null;
}
