<?php

namespace Modules\Items\Services\Itqan;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Itqan\LegacyLinks;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\CategoryStatus;
use Modules\Items\Enums\ProductStatus;
use Modules\Items\Support\Itqan\CategoryMapEntry;
use Modules\Items\Support\Itqan\CategoryMapFile;
use Modules\Items\Support\Itqan\CategoryMapProposer;
use Modules\Items\Support\Itqan\ItqanCategoryNodes;
use Modules\Items\Support\Itqan\ItqanNames;
use Modules\Items\Support\LegacyItemFields;
use RuntimeException;

/**
 * نقل أصناف إتقان بالشكل الجديد (التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §6.2 و§10):
 *
 * - الصنف الاب: المقطع مرة واحدة على مستوى الكل بـ (الاسم الموحَّد، الباركود، الوزن للمتر)
 *   (R-1). بلا باركود يبقى مسار التصنيف في المفتاح، فلا تُدمج أصناف عامة الاسم من تصنيفات
 *   مختلفة. تصنيفه أكثر تصنيف «سيستم» تكراراً بين أوراقه، والا «ألمنيوم».
 * - الابن (المتغيّر): واحد لكل (اب × مجموعة سعر) (R-3)، يحمل category_finish_id ولونه (R-2)،
 *   ورقمه رقم إتقان، ولا يحمل الباركود (R-11). يُنشأ فقط للورقة المستخدمة (او كل الألوان للعيّنة)؛
 *   الورقة التي لا ابن لها تُربط بالاب.
 * - مرآة المتجر: لكل لون قيمة في سمة «اللون»، ولكل ابن صف في product_attribute_value (R-6).
 * - التعارضات: اسم واحد بباركود او وزن مختلف، وباركود ووزن واحد بأسماء مختلفة (R-9)؛ لا دمج صامت.
 * - كل ورقة قديمة: ربط في legacy_links، او سطر في items-skipped.csv مع سببه.
 * - إعادة التشغيل تحدّث بالمفاتيح الطبيعية ولا تكرر، وتعطّل ما خرج من الخطة (R-10) الا في النقل الجزئي.
 *
 * رموز التصنيفات الجديدة تبدأ بعد اكبر رقم جذر في إتقان (R-12)، فلا تتصادم رموز الاباء مع
 * أرقام إتقان التي يحملها الأبناء.
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

    private const PARENT_SLUG = 'itqan-p-';

    private const CHILD_SLUG = 'itqan-';

    private const SEP = "\x1F";

    private ?string $reportDirectory = null;

    private int $codeFloor = 0;

    private bool $partial = false;

    public function __construct(private readonly LegacyLinks $links) {}

    public function withReportDirectory(string $directory): static
    {
        $this->reportDirectory = $directory;

        return $this;
    }

    /**
     * اكبر رقم جذر في ملف الربط كاملاً، فتبدأ رموز التصنيفات الجديدة بعده حتى في العيّنة (R-12).
     */
    public function withCodeFloor(int $maxLegacyRoot): static
    {
        $this->codeFloor = $maxLegacyRoot;

        return $this;
    }

    /**
     * نقل جزء من إتقان (العيّنة): لا يُعطَّل ما ليس فيه.
     */
    public function asPartial(): static
    {
        $this->partial = true;

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
        [$groups, $skipped, $exceptions, $conflicts] = $this->plan($rows, $leafIds, $map);

        $this->writeReports($skipped, $exceptions);

        $report = [
            'legacy_leaves' => count($leafIds),
            'skipped' => count($skipped),
            'exceptions' => $conflicts,
            'groups' => count($groups),
            'categories_created' => 0,
            'finishes_created' => 0,
            'category_finishes_created' => 0,
            'parents_created' => 0,
            'children_created' => 0,
            'parents_deactivated' => 0,
            'children_deactivated' => 0,
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
            $nodeGroups = $this->writeCategoryFinishes($map, $categoryIds, $finishIds, $report);
            $parents = $this->writeParents($groups, $categoryIds, $nodeGroups, $finishIds, $report);
            $log('Parents: '.count($parents));
            [$leafTargets, $childSlugs] = $this->writeChildren($groups, $parents, $nodeGroups, $finishIds, $usedLegacyIds, $allFinishes, $report);
            $log('Children written: '.$report['children_created']);
            $this->links->remember('items', Product::class, $leafTargets);
            $report['linked'] = count($leafTargets);

            if (! $this->partial) {
                $this->deactivateRemoved(array_column($parents, 'slug'), $childSlugs, $report);
            }
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
            fn ($id): bool => (string) $id !== CategoryMapProposer::TREE_ROOT
                && ! ItqanCategoryNodes::isCategory((string) $id, (string) ($rows[$id]['ItemType'] ?? ''), $hasChildren),
        ));

        return [$rows, array_map('strval', $leafIds)];
    }

    /**
     * تجميع الأوراق في أصناف آباء، وتحديد المتخطّى والمتعارض. لا يكتب شيئاً.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<int, string>  $leafIds
     * @param  array<string, CategoryMapEntry>  $map
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, array<int, string>>, 2: array<int, array<int, string>>, 3: int}
     */
    private function plan(array $rows, array $leafIds, array $map): array
    {
        $groups = [];
        $skipped = [];
        $byName = [];
        $byBarcode = [];

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
            $scope = $barcode === '' ? $entry->categoryPath : '';
            $key = implode(self::SEP, [$scope, $normalized, $barcode, $weight]);

            $groups[$key] ??= [
                'key' => $key,
                'name' => $name,
                'barcode' => $barcode === '' ? null : $barcode,
                'weight_per_meter' => $fields['weight_per_meter'] ?? null,
                'length' => $fields['length'] ?? null,
                'pricing_type' => $entry->pricingType,
                'colourless_weight' => $entry->finish === null && $entry->pricingType === 'weight',
                'paths' => [],
                'leaves' => [],
            ];
            $groups[$key]['paths'][$entry->categoryPath] = ($groups[$key]['paths'][$entry->categoryPath] ?? 0) + 1;
            $groups[$key]['leaves'][] = ['id' => $id, 'finish' => $entry->finish, 'node' => (string) $row['ItemParent'], 'path' => $entry->categoryPath];

            $byName[$scope.self::SEP.$normalized][$key] = true;
            if ($barcode !== '') {
                $byBarcode[$barcode.self::SEP.$weight][$key] = true;
            }
        }

        foreach ($groups as $key => $group) {
            $groups[$key]['path'] = self::dominantPath($group['paths']);
        }

        $exceptions = [];
        $conflicts = 0;
        foreach (['same_name' => $byName, 'same_barcode' => $byBarcode] as $kind => $clusters) {
            foreach ($clusters as $keys) {
                if (count($keys) < 2) {
                    continue;
                }

                $conflicts++;
                foreach (array_keys($keys) as $key) {
                    $group = $groups[$key];
                    $exceptions[] = [
                        $kind, $group['path'], $group['name'],
                        implode(' ', array_column($group['leaves'], 'id')),
                        (string) $group['weight_per_meter'], (string) $group['barcode'],
                    ];
                }
            }
        }

        return [$groups, $skipped, $exceptions, $conflicts];
    }

    /**
     * تصنيف الصنف الاب: اكثر مسار «سيستم» تكراراً بين أوراقه (لا جذر «ألمنيوم» وحده)، والأصغر
     * أبجدياً عند التساوي. اذا لم يكن له الا الجذر فهو الجذر.
     *
     * @param  array<string, int>  $paths
     */
    private static function dominantPath(array $paths): string
    {
        $systems = array_filter($paths, fn (string $path): bool => $path !== CategoryMapProposer::ALUMINUM_ROOT, ARRAY_FILTER_USE_KEY);
        $candidates = $systems !== [] ? $systems : $paths;

        uksort($candidates, fn (string $a, string $b): int => [$candidates[$b], $a] <=> [$candidates[$a], $b]);

        return (string) array_key_first($candidates);
    }

    /**
     * @param  array<int, array<int, string>>  $skipped
     * @param  array<int, array<int, string>>  $exceptions
     */
    private function writeReports(array $skipped, array $exceptions): void
    {
        $directory = $this->reportDirectory ?? storage_path('app/itqan');

        CategoryMapFile::writeCsv($directory.DIRECTORY_SEPARATOR.'items-skipped.csv', ['legacy_code', 'name', 'reason'], $skipped);
        CategoryMapFile::writeCsv(
            $directory.DIRECTORY_SEPARATOR.'exceptions.csv',
            ['kind', 'category_path', 'name', 'legacy_codes', 'weights', 'barcodes'],
            $exceptions,
        );
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
                    'status' => CategoryStatus::ACTIVE->value,
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
     * أول رمز جذر بعد اكبر رقم جذر في إتقان (خمس خانات، من ملف الربط كاملاً) وبعد اي رمز جذر موجود.
     *
     * @param  array<string, CategoryMapEntry>  $map
     */
    private function firstFreeRoot(array $map): int
    {
        $max = $this->codeFloor;
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
     * الألوان، ولكل لون قيمة في سمة «اللون» النظامية يقرأ منها المتجر (R-6).
     *
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

        $this->mirrorFinishes(array_values(array_unique($ids)));

        return $ids;
    }

    /**
     * لكل لون بلا قيمة في سمة «اللون» تُنشأ قيمة باسمه وتُربط به.
     *
     * @param  array<int, int>  $finishIds
     */
    private function mirrorFinishes(array $finishIds): void
    {
        $attributeId = Finish::colourAttributeId();
        $now = now();

        foreach (DB::table($this->table('finishes'))->whereIn('id', $finishIds)->whereNull('attribute_value_id')->get(['id', 'name']) as $finish) {
            $valueId = DB::table($this->table('attribute_values'))->insertGetId([
                'attribute_id' => $attributeId, 'sort_order' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table($this->table('attribute_values_translations'))->insert([
                'attribute_value_id' => $valueId, 'locale' => self::LOCALE, 'name' => $finish->name, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table($this->table('finishes'))->where('id', $finish->id)->update(['attribute_value_id' => $valueId]);
        }
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
     * @return array<string, int>  رقم العقدة القديمة => مجموعة السعر
     */
    private function writeCategoryFinishes(array $map, array $categoryIds, array $finishIds, array &$report): array
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

        return $links;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, int>  $categoryIds
     * @param  array<string, int>  $nodeGroups
     * @param  array<string, int>  $finishIds
     * @param  array<string, int|bool|string>  $report
     * @return array<string, array<string, mixed>>  مفتاح المجموعة => الصنف الاب (id، slug، sku، الاسم، التصنيف)
     */
    private function writeParents(array $groups, array $categoryIds, array $nodeGroups, array $finishIds, array &$report): array
    {
        $table = $this->table('products');
        $slugs = [];
        foreach ($groups as $key => $group) {
            $slugs[$key] = self::PARENT_SLUG.substr(sha1($key), 0, 24);
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

            // الموزون بلا لون (المطاط بالوزن) يُباع بنفسه: لونه «بلا لون» ومجموعة سعره مجموعة أوراقه (C-3).
            $colourless = $group['colourless_weight'];
            $rows[] = array_merge(
                $this->productRow(
                    $slugs[$key], $sku, $group, null,
                    $colourless ? $finishIds[''] : null,
                    $colourless ? ($nodeGroups[$group['leaves'][0]['node']] ?? null) : null,
                    $group['barcode'], $now,
                ),
                ['meta_data' => json_encode(['legacy_codes' => array_column($group['leaves'], 'id')], JSON_UNESCAPED_UNICODE)],
            );
            $parents[$key] = ['slug' => $slugs[$key], 'sku' => $sku, 'category_id' => $categoryId, 'name' => $group['name']];
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
     * @param  array<string, int>  $nodeGroups
     * @param  array<string, int>  $finishIds
     * @param  array<string, true>  $usedLegacyIds
     * @param  array<string, int|bool|string>  $report
     * @return array{0: array<string, int>, 1: array<int, string>}  رقم الورقة القديمة => الصنف (الابن او الاب)، وslugs الأبناء
     */
    private function writeChildren(array $groups, array $parents, array $nodeGroups, array $finishIds, array $usedLegacyIds, bool $allFinishes, array &$report): array
    {
        $now = now();
        $rows = [];
        $children = [];
        $targets = [];

        foreach ($groups as $key => $group) {
            $parent = $parents[$key];
            $byPriceGroup = [];

            foreach ($group['leaves'] as $leaf) {
                $targets[$leaf['id']] = ['parent' => $key];
                $priceGroup = $nodeGroups[$leaf['node']] ?? null;

                if ($leaf['finish'] === null || $priceGroup === null || (! $allFinishes && ! isset($usedLegacyIds[$leaf['id']]))) {
                    continue;
                }

                // ابن واحد لكل (اب × مجموعة سعر): لونان يختلفان كتابة فقط في المسار نفسه مجموعة واحدة.
                if (isset($byPriceGroup[$priceGroup])) {
                    $targets[$leaf['id']] = ['child' => $byPriceGroup[$priceGroup]];

                    continue;
                }

                $slug = self::CHILD_SLUG.$leaf['id'];
                $byPriceGroup[$priceGroup] = $slug;
                $targets[$leaf['id']] = ['child' => $slug];
                $rows[] = $this->productRow($slug, $leaf['id'], $group, $parent['id'], $finishIds[$leaf['finish']], $priceGroup, null, $now);
                $children[$slug] = [
                    'name' => $parent['name'].' - '.self::variantLabel($leaf, $group['path']),
                    'category_id' => $parent['category_id'],
                    'finish_id' => $finishIds[$leaf['finish']],
                ];
            }
        }

        $before = $this->productsBySlug(array_keys($children));
        $this->upsertProducts($rows, ['parent_id']);
        $ids = $this->productsBySlug(array_keys($children));
        $report['children_created'] = count($children) - count($before);

        $named = [];
        foreach ($children as $slug => $child) {
            $named[] = ['id' => $ids[$slug]['id'], 'name' => $child['name'], 'category_id' => $child['category_id']];
        }
        $this->writeNamesAndCategories($named);
        $this->mirrorChildren(array_map(fn (string $slug): array => [$ids[$slug]['id'], $children[$slug]['finish_id']], array_keys($children)));

        $resolved = array_map(fn (array $target): int => isset($target['child'])
            ? $ids[$target['child']]['id']
            : $parents[$target['parent']]['id'], $targets);

        return [$resolved, array_keys($children)];
    }

    /**
     * «اللون»، او «التصنيف اللون» اذا كانت مجموعة السعر من نظام غير تصنيف الاب (R-3).
     *
     * @param  array{finish: string, path: string}  $leaf
     */
    private static function variantLabel(array $leaf, string $parentPath): string
    {
        if ($leaf['path'] === $parentPath || $leaf['path'] === CategoryMapProposer::ALUMINUM_ROOT) {
            return $leaf['finish'];
        }

        $segments = explode('/', $leaf['path']);

        return end($segments).' '.$leaf['finish'];
    }

    /**
     * صف المتغيّر في سمة «اللون» ليقرأه المتجر (R-6). ادراج يتجاهل الموجود.
     *
     * @param  array<int, array{0: int, 1: int}>  $children  [معرّف الابن، معرّف اللون]
     */
    private function mirrorChildren(array $children): void
    {
        if ($children === []) {
            return;
        }

        $values = DB::table($this->table('finishes'))
            ->whereIn('id', array_values(array_unique(array_column($children, 1))))
            ->pluck('attribute_value_id', 'id')
            ->all();
        $now = now();
        $rows = [];

        foreach ($children as [$productId, $finishId]) {
            if (! empty($values[$finishId])) {
                $rows[] = ['product_id' => $productId, 'attribute_id' => (int) $values[$finishId], 'created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            DB::table($this->table('product_attribute_value'))->insertOrIgnore($chunk);
        }
    }

    /**
     * ما جاء من نقل سابق ولم يعد في الخطة يُعطَّل ولا يُحذف (R-10).
     *
     * @param  array<int, string>  $parentSlugs
     * @param  array<int, string>  $childSlugs
     * @param  array<string, int|bool|string>  $report
     */
    private function deactivateRemoved(array $parentSlugs, array $childSlugs, array &$report): void
    {
        $keepParents = array_flip($parentSlugs);
        $keepChildren = array_flip($childSlugs);
        $parentIds = [];
        $childIds = [];

        $rows = DB::table($this->table('products'))
            ->where('slug', 'like', self::CHILD_SLUG.'%')
            ->where('is_active', 1)
            ->get(['id', 'slug']);

        foreach ($rows as $row) {
            if (str_starts_with($row->slug, self::PARENT_SLUG)) {
                if (! isset($keepParents[$row->slug])) {
                    $parentIds[] = (int) $row->id;
                }
            } elseif (! isset($keepChildren[$row->slug])) {
                $childIds[] = (int) $row->id;
            }
        }

        foreach (array_chunk(array_merge($parentIds, $childIds), 1000) as $chunk) {
            DB::table($this->table('products'))->whereIn('id', $chunk)->update(['is_active' => 0, 'updated_at' => now()]);
        }

        $report['parents_deactivated'] = count($parentIds);
        $report['children_deactivated'] = count($childIds);
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function productRow(string $slug, string $sku, array $group, ?int $parentId, ?int $finishId, ?int $categoryFinishId, ?string $barcode, mixed $now): array
    {
        return [
            'slug' => $slug,
            'sku' => $sku,
            'barcode' => $barcode,
            'base_price' => 0,
            'weight_per_meter' => $group['weight_per_meter'],
            'length' => $group['length'],
            'pricing_type' => $group['pricing_type'],
            'parent_id' => $parentId,
            'finish_id' => $finishId,
            'category_finish_id' => $categoryFinishId,
            'status' => ProductStatus::PUBLISHED->value,
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
        $updates = array_merge(
            ['barcode', 'weight_per_meter', 'length', 'pricing_type', 'finish_id', 'category_finish_id', 'is_active', 'updated_at'],
            $extraUpdates,
        );

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
