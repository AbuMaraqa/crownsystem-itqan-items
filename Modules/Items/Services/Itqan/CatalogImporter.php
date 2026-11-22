<?php

namespace Modules\Items\Services\Itqan;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Itqan\LegacyLinks;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\Product;
use Modules\Items\Support\Itqan\CategoryMapEntry;
use Modules\Items\Support\Itqan\CategoryMapFile;
use Modules\Items\Support\Itqan\ItqanCategoryNodes;
use Modules\Items\Support\Itqan\ItqanNames;
use Modules\Items\Support\LegacyItemFields;
use RuntimeException;

/**
 * نقل أصناف إتقان بالشكل الجديد (التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §6.2):
 *
 * - التصنيف: مسار من ملف الربط (مثل «ألمنيوم/الوبين»)، يُربط بمساره في legacy_links.
 * - الصنف الاب: المقطع مرة واحدة لكل (تصنيف، اسم موحَّد، باركود، وزن للمتر). اسم واحد
 *   بوزن او باركود مختلف = صنفان، ويُكتب في exceptions.csv؛ لا دمج صامت.
 * - الابن: لون المقطع، رقمه رقم إتقان، ويُنشأ فقط للورقة المستخدمة (او كل الألوان للعيّنة).
 *   الورقة التي لا ابن لها تُربط بالاب.
 * - مجموعة السعر (التصنيف × اللون) لكل عقدة الالمنيوم، و(التصنيف × «بلا لون») للموزون بلا لون (C-3).
 * - كل ورقة قديمة: ربط في legacy_links، او سطر في items-skipped.csv مع سببه.
 *
 * رموز التصنيفات الجديدة تبدأ بعد اكبر رقم جذر في إتقان، فلا تتصادم رموز الاباء مع
 * أرقام إتقان التي يحملها الأبناء. إعادة التشغيل تحدّث بالمفاتيح الطبيعية ولا تكرر.
 */
class CatalogImporter
{
    public const CATEGORY_LINKS = 'catalog-categories';

    private const BATCH = 500;

    private const ROOT_DIGITS = 5;

    private const CHILD_CATEGORY_DIGITS = 3;

    /** المقاطع في الماركة الواحدة قد تتجاوز 999، فرقم الاب اربع خانات بعد رمز التصنيف. */
    private const PARENT_DIGITS = 4;

    private const LOCALE = 'ar';

    private const PUBLISHED = 2;

    private ?string $reportDirectory = null;

    public function __construct(private readonly LegacyLinks $links) {}

    public function withReportDirectory(string $directory): static
    {
        $this->reportDirectory = $directory;

        return $this;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $itemRows  صفوف items بأعمدة LegacyItemsImporter::LEGACY_COLUMNS
     * @param  array<string, CategoryMapEntry>  $map  بمفتاح رقم العقدة القديمة
     * @param  array<string, true>  $usedLegacyIds  الأوراق التي تُنشأ لها أبناء
     * @return array<string, int|bool|string>
     */
    public function import(iterable $itemRows, array $map, array $usedLegacyIds, bool $allFinishes, bool $dryRun, ?callable $log = null): array
    {
        $log ??= fn (string $message) => null;
        [$rows, $leafIds] = $this->readRows($itemRows);
        [$groups, $skipped, $exceptions] = $this->plan($rows, $leafIds, $map);

        $this->writeReports($skipped, $exceptions);

        $report = [
            'legacy_leaves' => count($leafIds),
            'skipped' => count($skipped),
            'exceptions' => count(array_unique(array_map(fn (array $row): string => $row[0]."\x1F".$row[1], $exceptions))),
            'groups' => count($groups),
            'categories_created' => 0,
            'finishes_created' => 0,
            'category_finishes_created' => 0,
            'parents_created' => 0,
            'children_created' => 0,
            'linked' => 0,
            'dry_run' => $dryRun,
        ];

        if ($dryRun) {
            return $report;
        }

        $this->guardAgainstOldImport();

        DB::transaction(function () use ($map, $groups, $usedLegacyIds, $allFinishes, $log, &$report): void {
            $categoryIds = $this->writeCategories($map, $report);
            $log('Categories: '.count($categoryIds));
            $finishIds = $this->writeFinishes($map, $report);
            $this->writeCategoryFinishes($map, $categoryIds, $finishIds, $report);
            $parents = $this->writeParents($groups, $categoryIds, $finishIds, $report);
            $log('Parents: '.count($parents));
            $leafTargets = $this->writeChildren($groups, $parents, $finishIds, $usedLegacyIds, $allFinishes, $report);
            $log('Children written: '.$report['children_created']);
            $this->links->remember('items', Product::class, $leafTargets);
            $report['linked'] = count($leafTargets);
        });

        return $report;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $itemRows
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, string>}
     */
    private function readRows(iterable $itemRows): array
    {
        $rows = [];
        $hasChildren = [];

        foreach ($itemRows as $row) {
            $id = trim((string) $row['ItemId']);
            $parent = trim((string) ($row['ItemParent'] ?? '0'));
            $row['ItemId'] = $id;
            $row['ItemParent'] = $parent;
            $rows[$id] = $row;

            if ($parent !== $id) {
                $hasChildren[$parent] = true;
            }
        }

        $leafIds = array_values(array_filter(
            array_keys($rows),
            fn ($id): bool => (string) $id !== '0' && ! ItqanCategoryNodes::isCategory((string) $id, (string) ($rows[$id]['ItemType'] ?? ''), $hasChildren),
        ));

        return [$rows, array_map('strval', $leafIds)];
    }

    /**
     * تجميع الأوراق في أصناف آباء، وتحديد المتخطّى والمتعارض. لا يكتب شيئاً.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<int, string>  $leafIds
     * @param  array<string, CategoryMapEntry>  $map
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, array<int, string>>, 2: array<int, array<int, string>>}
     */
    private function plan(array $rows, array $leafIds, array $map): array
    {
        $groups = [];
        $skipped = [];
        $keysByName = [];

        foreach ($leafIds as $id) {
            $row = $rows[$id];
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $row['ItemString']));
            $entry = $map[$row['ItemParent']] ?? null;
            $reason = match (true) {
                $entry === null => 'unmapped category '.$row['ItemParent'],
                ! $entry->kept() => $entry->note !== '' ? $entry->note : 'category skipped',
                strtoupper(trim((string) $row['isActive'])) === 'N' => 'inactive',
                default => null,
            };

            if ($reason !== null) {
                $skipped[] = [$id, $name, $reason];

                continue;
            }

            $type = (string) $row['ItemType'];
            $fields = LegacyItemFields::columns($type, $row);
            $weight = isset($fields['weight_per_meter']) ? number_format((float) $fields['weight_per_meter'], 3, '.', '') : '';
            $barcode = (string) (LegacyItemFields::barcodes((string) $row['Barcode'], $type, $row)['primary'] ?? '');
            $normalized = ItqanNames::normalize($name);
            $key = implode("\x1F", [$entry->categoryPath, $normalized, $barcode, $weight]);

            $groups[$key] ??= [
                'key' => $key,
                'path' => $entry->categoryPath,
                'name' => $name,
                'barcode' => $barcode === '' ? null : $barcode,
                'weight_per_meter' => $fields['weight_per_meter'] ?? null,
                'length' => $fields['length'] ?? null,
                'pricing_type' => $entry->pricingType,
                'colourless_weight' => $entry->finish === null && $entry->pricingType === 'weight',
                'leaves' => [],
            ];
            $groups[$key]['leaves'][] = ['id' => $id, 'finish' => $entry->finish];
            $keysByName[$entry->categoryPath."\x1F".$normalized][$key] = true;
        }

        $exceptions = [];
        foreach ($keysByName as $keys) {
            if (count($keys) < 2) {
                continue;
            }

            foreach (array_keys($keys) as $key) {
                $group = $groups[$key];
                $exceptions[] = [
                    $group['path'], $group['name'],
                    implode(' ', array_column($group['leaves'], 'id')),
                    (string) $group['weight_per_meter'], (string) $group['barcode'],
                ];
            }
        }

        return [$groups, $skipped, $exceptions];
    }

    /**
     * @param  array<int, array<int, string>>  $skipped
     * @param  array<int, array<int, string>>  $exceptions
     */
    private function writeReports(array $skipped, array $exceptions): void
    {
        $directory = $this->reportDirectory ?? storage_path('app/itqan');

        CategoryMapFile::writeCsv($directory.DIRECTORY_SEPARATOR.'items-skipped.csv', ['legacy_code', 'name', 'reason'], $skipped);
        CategoryMapFile::writeCsv($directory.DIRECTORY_SEPARATOR.'exceptions.csv', ['category_path', 'name', 'legacy_codes', 'weights', 'barcodes'], $exceptions);
    }

    /**
     * النقل الجديد لا يُخلط بأصناف النقل القديم (رقم إتقان في sku عند الاثنين).
     */
    private function guardAgainstOldImport(): void
    {
        if (DB::table($this->table('products'))->where('slug', 'like', 'old-%')->exists()) {
            throw new RuntimeException('Products from the previous Itqan import exist (slug old-*). Import the new catalog into an empty items table.');
        }
    }

    /**
     * @param  array<string, CategoryMapEntry>  $map
     * @param  array<string, int|bool|string>  $report
     * @return array<string, int>  مسار التصنيف => معرّفه
     */
    private function writeCategories(array $map, array &$report): array
    {
        $paths = [];
        foreach ($map as $entry) {
            if (! $entry->kept()) {
                continue;
            }

            $segments = explode('/', $entry->categoryPath);
            for ($depth = 1; $depth <= count($segments); $depth++) {
                $path = implode('/', array_slice($segments, 0, $depth));
                $paths[$path] ??= $entry->pricingType;
            }
        }

        uksort($paths, fn (string $a, string $b): int => [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b]);

        $ids = $this->links->map(self::CATEGORY_LINKS, Category::class);
        $codes = DB::table($this->table('categories'))->whereIn('id', array_values($ids) ?: [0])->pluck('code', 'id')->all();
        $nextRoot = $this->firstFreeRoot($map);
        $childCounters = [];
        $now = now();

        foreach ($paths as $path => $pricingType) {
            $segments = explode('/', $path);
            $name = end($segments);
            $parentPath = implode('/', array_slice($segments, 0, -1));
            $parentId = $parentPath === '' ? null : $ids[$parentPath];

            if (! isset($ids[$path])) {
                $code = $parentId === null
                    ? str_pad((string) $nextRoot++, self::ROOT_DIGITS, '0', STR_PAD_LEFT)
                    : $this->nextCode((string) $codes[$parentId], self::CHILD_CATEGORY_DIGITS, $childCounters, $this->table('categories'), 'code');

                $ids[$path] = (int) DB::table($this->table('categories'))->insertGetId([
                    'slug' => 'cat-'.$code,
                    'code' => $code,
                    'status' => 1,
                    'sort_order' => 0,
                    'parent_id' => $parentId,
                    'pricing_type' => $pricingType,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $codes[$ids[$path]] = $code;
                $report['categories_created']++;
            }

            DB::table($this->table('category_translations'))->upsert(
                [['category_id' => $ids[$path], 'locale' => self::LOCALE, 'name' => $name]],
                ['category_id', 'locale'],
                ['name'],
            );
        }

        $this->links->remember(self::CATEGORY_LINKS, Category::class, array_intersect_key($ids, $paths));

        $nodeCategories = [];
        foreach ($map as $code => $entry) {
            if ($entry->kept()) {
                $nodeCategories[(string) $code] = $ids[$entry->categoryPath];
            }
        }
        $this->links->remember('items', Category::class, $nodeCategories);

        return $ids;
    }

    /**
     * أول رمز جذر بعد اكبر رقم جذر في إتقان (خمس خانات) وبعد اي رمز جذر موجود.
     *
     * @param  array<string, CategoryMapEntry>  $map
     */
    private function firstFreeRoot(array $map): int
    {
        $max = 0;
        foreach (array_keys($map) as $code) {
            $code = (string) $code;
            if (strlen($code) === self::ROOT_DIGITS && ctype_digit($code)) {
                $max = max($max, (int) $code);
            }
        }

        foreach (DB::table($this->table('categories'))->whereNull('parent_id')->pluck('code') as $code) {
            if (strlen((string) $code) === self::ROOT_DIGITS && ctype_digit((string) $code)) {
                $max = max($max, (int) $code);
            }
        }

        return $max + 1;
    }

    /**
     * الرمز التالي تحت رمز اب: الرمز ثم تسلسل بعدد خانات ثابت، بعد اكبر تسلسل مستخدم.
     *
     * @param  array<string, int>  $counters
     */
    private function nextCode(string $prefix, int $digits, array &$counters, string $table, string $column): string
    {
        $counterKey = $table.'|'.$prefix.'|'.$digits;

        if (! isset($counters[$counterKey])) {
            $length = strlen($prefix) + $digits;
            $counters[$counterKey] = DB::table($table)
                ->where($column, 'like', $prefix.'%')
                ->pluck($column)
                ->filter(fn ($value): bool => strlen((string) $value) === $length && ctype_digit(substr((string) $value, -$digits)))
                ->map(fn ($value): int => (int) substr((string) $value, -$digits))
                ->max() ?? 0;
        }

        $next = ++$counters[$counterKey];

        if ($next >= 10 ** $digits) {
            throw new RuntimeException("No free code left under {$prefix} ({$digits} digits).");
        }

        return $prefix.str_pad((string) $next, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, CategoryMapEntry>  $map
     * @param  array<string, int|bool|string>  $report
     * @return array<string, int>  اسم اللون => معرّفه، و'' => «بلا لون»
     */
    private function writeFinishes(array $map, array &$report): array
    {
        $names = [];
        foreach ($map as $entry) {
            if ($entry->kept() && $entry->finish !== null) {
                $names[$entry->finish] = true;
            }
        }

        // «FINE IRON» و«fine Iron» لون واحد: المقارنة بلا حالة أحرف في PHP، فيتصرف
        // PostgreSQL (حساس للحالة) وMySQL (غير حساس في الفهرس الفريد) بالطريقة نفسها.
        $table = $this->table('finishes');
        $existing = [];
        foreach (DB::table($table)->get(['id', 'name']) as $row) {
            $existing[self::finishKey((string) $row->name)] = (int) $row->id;
        }

        $new = [];
        foreach (array_keys($names) as $name) {
            $key = self::finishKey((string) $name);
            if (! isset($existing[$key]) && ! isset($new[$key])) {
                $new[$key] = (string) $name;
            }
        }

        $now = now();
        foreach ($new as $key => $name) {
            $existing[$key] = (int) DB::table($table)->insertGetId([
                'name' => $name, 'is_system' => false, 'is_active' => true, 'sort_order' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $report['finishes_created'] = count($new);

        $ids = [];
        foreach (array_keys($names) as $name) {
            $ids[(string) $name] = $existing[self::finishKey((string) $name)];
        }
        $ids[''] = (int) Finish::system()->getKey();

        return $ids;
    }

    private static function finishKey(string $name): string
    {
        return mb_strtolower(ItqanNames::normalize($name));
    }

    /**
     * @param  array<string, CategoryMapEntry>  $map
     * @param  array<string, int>  $categoryIds
     * @param  array<string, int>  $finishIds
     * @param  array<string, int|bool|string>  $report
     */
    private function writeCategoryFinishes(array $map, array $categoryIds, array $finishIds, array &$report): void
    {
        $table = $this->table('category_finishes');
        $wanted = [];

        foreach ($map as $code => $entry) {
            if (! $entry->kept() || ($entry->finish === null && $entry->pricingType !== 'weight')) {
                continue;
            }

            $pair = $categoryIds[$entry->categoryPath].'|'.$finishIds[$entry->finish ?? ''];
            $wanted[$pair] ??= ['legacy_code' => (string) $code, 'nodes' => []];
            $wanted[$pair]['nodes'][] = (string) $code;
        }

        $existing = [];
        foreach (DB::table($table)->get(['id', 'category_id', 'finish_id']) as $row) {
            $existing[$row->category_id.'|'.$row->finish_id] = (int) $row->id;
        }

        $now = now();
        $links = [];
        foreach ($wanted as $pair => $group) {
            if (! isset($existing[$pair])) {
                [$categoryId, $finishId] = array_map('intval', explode('|', $pair));
                $existing[$pair] = (int) DB::table($table)->insertGetId([
                    'category_id' => $categoryId, 'finish_id' => $finishId, 'legacy_code' => $group['legacy_code'],
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $report['category_finishes_created']++;
            }

            foreach ($group['nodes'] as $node) {
                $links[$node] = $existing[$pair];
            }
        }

        $this->links->remember('items', CategoryFinish::class, $links);
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, int>  $categoryIds
     * @param  array<string, int>  $finishIds
     * @param  array<string, int|bool|string>  $report
     * @return array<string, array{id: int, sku: string}>  مفتاح المجموعة => الصنف الاب
     */
    private function writeParents(array $groups, array $categoryIds, array $finishIds, array &$report): array
    {
        $table = $this->table('products');
        $slugs = [];
        foreach ($groups as $key => $group) {
            $slugs[$key] = 'itqan-p-'.substr(sha1($key), 0, 24);
        }

        $existing = $this->productsBySlug(array_values($slugs));
        $categoryCodes = DB::table($this->table('categories'))->whereIn('id', array_values($categoryIds) ?: [0])->pluck('code', 'id')->all();
        $counters = [];
        $now = now();
        $rows = [];
        $parents = [];

        foreach ($groups as $key => $group) {
            $categoryId = $categoryIds[$group['path']];
            $sku = $existing[$slugs[$key]]['sku']
                ?? $this->nextCode((string) $categoryCodes[$categoryId], self::PARENT_DIGITS, $counters, $table, 'sku');

            if (! isset($existing[$slugs[$key]])) {
                $report['parents_created']++;
            }

            $rows[] = array_merge($this->productRow($slugs[$key], $sku, $group, null, $group['colourless_weight'] ? $finishIds[''] : null, $now), [
                'meta_data' => json_encode(['legacy_codes' => array_column($group['leaves'], 'id')], JSON_UNESCAPED_UNICODE),
            ]);
            $parents[$key] = ['sku' => $sku, 'category_id' => $categoryId, 'name' => $group['name']];
        }

        $this->upsertProducts($rows, ['meta_data']);

        $ids = $this->productsBySlug(array_values($slugs));
        foreach ($parents as $key => $parent) {
            $parents[$key]['id'] = $ids[$slugs[$key]]['id'];
        }

        $this->writeNamesAndCategories(array_map(fn (array $parent): array => [
            'id' => $parent['id'], 'name' => $parent['name'], 'category_id' => $parent['category_id'],
        ], $parents));

        return $parents;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, array<string, mixed>>  $parents
     * @param  array<string, int>  $finishIds
     * @param  array<string, true>  $usedLegacyIds
     * @param  array<string, int|bool|string>  $report
     * @return array<string, int>  رقم الورقة القديمة => معرّف الصنف (الابن او الاب)
     */
    private function writeChildren(array $groups, array $parents, array $finishIds, array $usedLegacyIds, bool $allFinishes, array &$report): array
    {
        $now = now();
        $rows = [];
        $children = [];
        $targets = [];

        foreach ($groups as $key => $group) {
            $parent = $parents[$key];
            $byFinish = [];

            foreach ($group['leaves'] as $leaf) {
                $targets[$leaf['id']] = ['parent' => $key];

                if ($leaf['finish'] === null || (! $allFinishes && ! isset($usedLegacyIds[$leaf['id']]))) {
                    continue;
                }

                if (isset($byFinish[$leaf['finish']])) {
                    $targets[$leaf['id']] = ['child' => $byFinish[$leaf['finish']]];

                    continue;
                }

                $slug = 'itqan-'.$leaf['id'];
                $byFinish[$leaf['finish']] = $slug;
                $targets[$leaf['id']] = ['child' => $slug];
                $rows[] = $this->productRow($slug, $leaf['id'], $group, $parent['id'], $finishIds[$leaf['finish']], $now);
                $children[$slug] = [
                    'name' => $parent['name'].' - '.$leaf['finish'],
                    'category_id' => $parent['category_id'],
                ];
            }
        }

        $before = $this->productsBySlug(array_keys($children));
        $this->upsertProducts($rows, ['parent_id']);
        $ids = $this->productsBySlug(array_keys($children));
        $report['children_created'] = count($children) - count($before);

        $this->writeNamesAndCategories(array_map(fn (string $slug): array => $children[$slug] + ['id' => $ids[$slug]['id']], array_combine(array_keys($children), array_keys($children))));

        return array_map(fn (array $target): int => isset($target['child'])
            ? $ids[$target['child']]['id']
            : $parents[$target['parent']]['id'], $targets);
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function productRow(string $slug, string $sku, array $group, ?int $parentId, ?int $finishId, mixed $now): array
    {
        return [
            'slug' => $slug,
            'sku' => $sku,
            'barcode' => $group['barcode'],
            'base_price' => 0,
            'weight_per_meter' => $group['weight_per_meter'],
            'length' => $group['length'],
            'pricing_type' => $group['pricing_type'],
            'parent_id' => $parentId,
            'finish_id' => $finishId,
            'status' => self::PUBLISHED,
            'is_active' => 1,
            'meta_data' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $extraUpdates
     */
    private function upsertProducts(array $rows, array $extraUpdates): void
    {
        $updates = array_merge(['barcode', 'weight_per_meter', 'length', 'pricing_type', 'finish_id', 'is_active', 'updated_at'], $extraUpdates);

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            DB::table($this->table('products'))->upsert($chunk, ['slug'], $updates);
        }
    }

    /**
     * اسم الصنف بالعربية وتصنيفه: يُستبدلان كاملين، لان جدولي الترجمة والتصنيف بلا مفتاح فريد.
     *
     * @param  array<array-key, array{id: int, name: string, category_id: int}>  $products
     */
    private function writeNamesAndCategories(array $products): void
    {
        $products = array_values($products);
        $now = now();

        foreach (array_chunk($products, self::BATCH) as $chunk) {
            $ids = array_column($chunk, 'id');

            DB::table($this->table('product_translations'))->whereIn('product_id', $ids)->where('locale', self::LOCALE)->delete();
            DB::table($this->table('product_translations'))->insert(array_map(fn (array $product): array => [
                'product_id' => $product['id'], 'locale' => self::LOCALE, 'name' => mb_substr($product['name'], 0, 255),
            ], $chunk));

            DB::table($this->table('products_categories'))->whereIn('product_id', $ids)->delete();
            DB::table($this->table('products_categories'))->insert(array_map(fn (array $product): array => [
                'product_id' => $product['id'], 'category_id' => $product['category_id'], 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
    }

    /**
     * @param  array<int, string>  $slugs
     * @return array<string, array{id: int, sku: string}>
     */
    private function productsBySlug(array $slugs): array
    {
        $found = [];

        foreach (array_chunk($slugs, 1000) as $chunk) {
            foreach (DB::table($this->table('products'))->whereIn('slug', $chunk)->get(['id', 'slug', 'sku']) as $row) {
                $found[$row->slug] = ['id' => (int) $row->id, 'sku' => (string) $row->sku];
            }
        }

        return $found;
    }

    private function table(string $name): string
    {
        return config('items.table_prefix').$name;
    }
}
