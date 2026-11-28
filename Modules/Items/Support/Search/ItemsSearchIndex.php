<?php

namespace Modules\Items\Support\Search;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Search\SearchIndex;
use Modules\Core\Support\Search\TextNormalizer;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;

/**
 * فهرس البحث في الاصناف والتصنيفات: لكل صنف مستند (في جدول items_product_search)
 * ولكل تصنيف مستند (في عمود search_text)، فيه كلماته موحَّدة ومحاطة بمسافتين
 * ليُطابَق اول الكلمة بـ LIKE '% كلمة%'.
 *
 * والمستند نفسه يُرسل الى محرك البحث (Scout + Meilisearch) من القراءة نفسها، مع حقول
 * التصفية (النوع، التسعير، الحالة، التصنيفات)، فلا يُبنى مستند الصنف في مكانين.
 *
 * مستند الصنف: اسماؤه بكل اللغات، رقمه، باركوده وباركوداته الاضافية، رفّه، الصنف المرادف،
 * تصنيفاته (اسماً ورقماً)، العلامة التجارية والشركات المصنّعة.
 * الصنف المتفرع (variation) والمحذوف لا مستند له، فلا يظهر في البحث.
 *
 * التعديلات تُسجَّل اثناء الطلب وتُكتب في آخره (flush)، بعد ربط التصنيفات
 * الذي يأتي عادة بعد حفظ الصنف، وبتحديث واحد لكل دفعة.
 */
final class ItemsSearchIndex
{
    private const CHUNK = 500;

    /**
     * اطول مستند يُخزَّن، والزائد كلمات اقل وزناً (من آخر المستند).
     */
    private const DOCUMENT_MAX_LENGTH = 4000;

    /**
     * @var array<string, array<int, true>>
     */
    private array $pending = ['products' => [], 'categories' => [], 'brands' => [], 'manufacturers' => []];

    /**
     * مستندات محرك البحث المقروءة مسبقاً لدفعة يفهرسها Scout (prime).
     *
     * @var array{products: array<int, array<string, mixed>|null>, categories: array<int, array<string, mixed>|null>}
     */
    private array $primed = ['products' => [], 'categories' => []];

    public function markProduct(mixed $id): void
    {
        $this->mark('products', $id);
    }

    public function markCategory(mixed $id): void
    {
        $this->mark('categories', $id);
    }

    public function markBrand(mixed $id): void
    {
        $this->mark('brands', $id);
    }

    public function markManufacturer(mixed $id): void
    {
        $this->mark('manufacturers', $id);
    }

    public function hasPending(): bool
    {
        return array_filter($this->pending) !== [];
    }

    /**
     * كتابة ما تغيّر اثناء الطلب: التصنيفات واصنافها، واصناف العلامات
     * والشركات المعدّلة، والاصناف ومن جعلها صنفاً مرادفاً.
     */
    public function flush(): void
    {
        if (! $this->hasPending()) {
            return;
        }

        $pending = array_map('array_keys', $this->pending);
        $this->pending = array_map(fn (): array => [], $this->pending);

        if (! SearchCapabilities::hasProductIndex() && ! SearchIndex::configured()) {
            return;
        }

        $prefix = config('items.table_prefix');
        $productIds = $pending['products'];

        if ($pending['categories'] !== []) {
            $this->refreshCategories($pending['categories']);
            $productIds = [...$productIds, ...$this->pluckIds($prefix.'products_categories', 'product_id', 'category_id', $pending['categories'])];
        }

        if ($pending['brands'] !== []) {
            $productIds = [...$productIds, ...$this->pluckIds($prefix.'products', 'id', 'brand_id', $pending['brands'])];
        }

        if ($pending['manufacturers'] !== []) {
            $productIds = [...$productIds, ...$this->pluckIds($prefix.'products_manufacturers', 'product_id', 'manufacturer_id', $pending['manufacturers'])];
        }

        if ($pending['products'] !== []) {
            // اسم الصنف جزء من مستند كل صنف جعله مرادفاً له.
            $productIds = [...$productIds, ...$this->pluckIds($prefix.'products', 'id', 'synonym_product_id', $pending['products'])];

            // تصنيفات الصنف الرئيسي يُصفّى بها اصنافه الفرعية في محرك البحث.
            if (SearchIndex::configured()) {
                $productIds = [...$productIds, ...$this->pluckIds($prefix.'products', 'id', 'main_product_id', $pending['products'])];
            }
        }

        $this->refreshProducts(array_values(array_unique($productIds)));
    }

    /**
     * اعادة بناء الفهرس كله، دفعة بعد دفعة.
     *
     * @param  (Closure(int): void)|null  $progress  يُستدعى بعدد الاصناف المفهرسة بعد كل دفعة
     * @param  bool  $database  فهرس قاعدة البيانات (items_product_search)
     * @param  bool  $engine  فهرس محرك البحث (Meilisearch)
     * @return int عدد الاصناف المفهرسة
     */
    public function rebuild(?Closure $progress = null, int $chunk = self::CHUNK, bool $database = true, bool $engine = true): int
    {
        $database = $database && SearchCapabilities::hasProductIndex();
        $engine = $engine && SearchIndex::configured();

        if (! $database && ! $engine) {
            return 0;
        }

        $this->rebuildCategories($chunk, $database, $engine);

        return $this->rebuildProducts($progress, $chunk, $database, $engine);
    }

    /**
     * @param  (Closure(int): void)|null  $progress
     */
    public function rebuildProducts(?Closure $progress = null, int $chunk = self::CHUNK, bool $database = true, bool $engine = true): int
    {
        $count = 0;

        DB::table(config('items.table_prefix').'products')->select('id')->orderBy('id')
            ->chunkById($chunk, function (Collection $rows) use (&$count, $progress, $database, $engine): void {
                $this->refreshProducts($rows->pluck('id')->all(), $database, $engine);
                $count += $rows->count();

                if ($progress) {
                    $progress($count);
                }
            });

        return $count;
    }

    public function rebuildCategories(int $chunk = self::CHUNK, bool $database = true, bool $engine = true): int
    {
        $count = 0;

        DB::table(config('items.table_prefix').'categories')->select('id')->orderBy('id')
            ->chunkById($chunk, function (Collection $rows) use (&$count, $database, $engine): void {
                $this->refreshCategories($rows->pluck('id')->all(), $database, $engine);
                $count += $rows->count();
            });

        return $count;
    }

    /**
     * كتابة مستندات الاصناف في فهرس قاعدة البيانات وفي محرك البحث معاً، من قراءة واحدة.
     *
     * @param  array<int, mixed>  $ids
     */
    public function refreshProducts(array $ids, bool $database = true, bool $engine = true): void
    {
        $database = $database && SearchCapabilities::hasProductIndex();
        $engine = $engine && SearchIndex::configured();

        foreach (array_chunk($this->normalizeIds($ids), self::CHUNK) as $chunk) {
            $records = $this->productRecords($chunk);

            if ($database) {
                $this->writeProducts($chunk, array_map(fn (?array $record): ?string => $record['document'] ?? null, $records));
            }

            if ($engine) {
                $this->send(Product::class, $chunk, $records);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    public function refreshCategories(array $ids, bool $database = true, bool $engine = true): void
    {
        $database = $database && SearchCapabilities::hasCategoryIndex();
        $engine = $engine && SearchIndex::configured();

        if (! $database && ! $engine) {
            return;
        }

        foreach (array_chunk($this->normalizeIds($ids), self::CHUNK) as $chunk) {
            $records = $this->categoryRecords($chunk);

            if ($database) {
                $this->writeCategories(array_map(fn (?array $record): ?string => $record['document'] ?? null, $records));
            }

            if ($engine) {
                $this->send(Category::class, $chunk, $records);
            }
        }
    }

    /**
     * مستند السجل (صنف او تصنيف) في محرك البحث، لـ toSearchableArray: من دفعة مقروءة مسبقاً
     * (prime) او بقراءة السجل وحده. فارغ لما لا يُبحث فيه (المتفرع، المحذوف)، فيتركه Scout.
     *
     * @param  class-string<Product|Category>  $model
     * @return array<string, mixed>
     */
    public function engineDocument(string $model, int $id): array
    {
        $type = self::type($model);

        if (! array_key_exists($id, $this->primed[$type])) {
            $this->prime($model, [$id]);
        }

        return $this->primed[$type][$id] ?? [];
    }

    /**
     * قراءة مستندات دفعة مرة واحدة قبل ان يطلبها Scout سجلاً سجلاً (makeSearchableUsing).
     *
     * @param  class-string<Product|Category>  $model
     * @param  array<int, mixed>  $ids
     */
    public function prime(string $model, array $ids): void
    {
        $ids = $this->normalizeIds($ids);
        $records = self::type($model) === 'products' ? $this->productRecords($ids) : $this->categoryRecords($ids);

        $this->primed[self::type($model)] = array_map(fn (?array $record): ?array => $record['engine'] ?? null, $records);
    }

    /**
     * بناء فهرس الجدول في محرك البحث وحده (search:sync)؛ فهرس قاعدة البيانات يبنيه items:search-index.
     *
     * @param  class-string<Product|Category>  $model
     * @param  (Closure(int): void)|null  $progress
     */
    public function rebuildEngine(string $model, ?Closure $progress = null, int $chunk = self::CHUNK): int
    {
        return self::type($model) === 'products'
            ? $this->rebuildProducts($progress, $chunk, database: false)
            : $this->rebuildCategories($chunk, database: false);
    }

    /**
     * اعدادات الفهرس في Meilisearch. الاصناف: الرمز ثم الاسم ثم باقي المستند، والرمز بلا
     * تصحيح املائي، والتصفية بالنوع والتسعير والحالة والتصنيف. التصنيفات: الرقم ثم الاسم.
     *
     * @param  class-string<Product|Category>  $model
     * @return array<string, mixed>
     */
    public static function indexSettings(string $model): array
    {
        $common = [
            'proximityPrecision' => 'byAttribute',
            'pagination' => ['maxTotalHits' => SearchIndex::MAX_HITS + 1],
        ];

        return self::type($model) === 'products' ? [
            'searchableAttributes' => ['codes', 'names', 'document'],
            'filterableAttributes' => ['is_active', 'pricing_type', 'item_type', 'category_ids'],
            'typoTolerance' => ['disableOnAttributes' => ['codes'], 'disableOnNumbers' => true],
            ...$common,
        ] : [
            'searchableAttributes' => ['code', 'names', 'document'],
            'typoTolerance' => ['disableOnAttributes' => ['code'], 'disableOnNumbers' => true],
            ...$common,
        ];
    }

    /**
     * @return 'products'|'categories'
     */
    private static function type(string $model): string
    {
        return is_a($model, Category::class, true) ? 'categories' : 'products';
    }

    /**
     * ارسال مستندات دفعة الى محرك البحث، وحذف ما لا يُبحث فيه منها.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  array<int, int>  $ids
     * @param  array<int, array{document: string, engine: array<string, mixed>}|null>  $records
     */
    private function send(string $model, array $ids, array $records): void
    {
        $documents = array_values(array_filter(array_map(fn (?array $record): ?array => $record['engine'] ?? null, $records)));
        $indexed = array_map(fn (array $document): int => (int) $document['id'], $documents);

        SearchIndex::put($model, $documents);
        SearchIndex::forget($model, array_values(array_diff($ids, $indexed)));
    }

    /**
     * مستند كل صنف: نص فهرس قاعدة البيانات، ومستند محرك البحث (الرموز، الاسماء، باقي
     * المستند، وحقول التصفية). null للصنف المتفرع والمحذوف.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{document: string, engine: array<string, mixed>}|null>
     */
    private function productRecords(array $ids): array
    {
        $prefix = config('items.table_prefix');

        $products = DB::table($prefix.'products')
            ->whereIn('id', $ids)
            ->get(['id', 'sku', 'barcode', 'shelf', 'parent_id', 'deleted_at', 'synonym_product_id', 'brand_id', 'main_product_id', 'is_active', 'pricing_type', 'item_type']);

        $synonymIds = $products->pluck('synonym_product_id')->filter()->unique()->values()->all();

        $names = DB::table($prefix.'product_translations')
            ->whereIn('product_id', [...$ids, ...$synonymIds])
            ->whereNotNull('name')
            ->get(['product_id', 'name'])
            ->groupBy('product_id');

        $synonymSkus = $synonymIds === [] ? collect() : DB::table($prefix.'products')
            ->whereIn('id', $synonymIds)
            ->whereNull('deleted_at')
            ->pluck('sku', 'id');

        $categories = DB::table($prefix.'products_categories as pivot')
            ->join($prefix.'categories as categories', 'categories.id', '=', 'pivot.category_id')
            ->leftJoin($prefix.'category_translations as translations', 'translations.category_id', '=', 'categories.id')
            ->whereIn('pivot.product_id', $ids)
            ->whereNull('categories.deleted_at')
            ->get(['pivot.product_id', 'categories.code', 'translations.name'])
            ->groupBy('product_id');

        $brandIds = $products->pluck('brand_id')->filter()->unique()->values()->all();

        $brands = $brandIds === [] ? collect() : DB::table($prefix.'brand_translations')
            ->whereIn('brand_id', $brandIds)
            ->get(['brand_id', 'name'])
            ->groupBy('brand_id');

        $manufacturers = DB::table($prefix.'products_manufacturers as pivot')
            ->join($prefix.'manufacturer_translations as translations', 'translations.manufacturer_id', '=', 'pivot.manufacturer_id')
            ->whereIn('pivot.product_id', $ids)
            ->get(['pivot.product_id', 'translations.name'])
            ->groupBy('product_id');

        $secondaryBarcodes = SearchCapabilities::hasSecondaryBarcodes()
            ? DB::table($prefix.'product_barcodes')->whereIn('product_id', $ids)->orderBy('sort_order')->get(['product_id', 'barcode'])->groupBy('product_id')
            : collect();

        // تصنيفات الصنف وتصنيفات صنفه الرئيسي، للتصفية في محرك البحث (كما في SearchFilters).
        $mainIds = $products->pluck('main_product_id')->filter()->unique()->values()->all();
        $categoryIds = DB::table($prefix.'products_categories')
            ->whereIn('product_id', [...$ids, ...$mainIds])
            ->get(['product_id', 'category_id'])
            ->groupBy('product_id')
            ->map(fn (Collection $rows): array => $rows->pluck('category_id')->map(fn (mixed $id): int => (int) $id)->all());

        $records = [];

        foreach ($products as $product) {
            if ($product->parent_id !== null || $product->deleted_at !== null) {
                $records[(int) $product->id] = null;

                continue;
            }

            $synonymId = $product->synonym_product_id;
            $productCategories = $categories->get($product->id, collect());
            $productNames = $names->get($product->id, collect())->pluck('name')->all();
            $barcodes = $secondaryBarcodes->get($product->id, collect())->pluck('barcode')->all();

            // الترتيب بحسب الاهمية، فإن طال المستند سقط آخره.
            $document = $this->document([
                ...$productNames,
                ...$this->codeTexts($product->sku),
                ...$this->codeTexts($product->barcode),
                ...array_merge([], ...array_map(fn (?string $barcode): array => $this->codeTexts($barcode), $barcodes)),
                $product->shelf,
                ...($synonymId ? $names->get($synonymId, collect())->pluck('name')->all() : []),
                ...($synonymId ? $this->codeTexts($synonymSkus->get($synonymId)) : []),
                ...$productCategories->pluck('name')->all(),
                ...$productCategories->pluck('code')->all(),
                ...($product->brand_id ? $brands->get($product->brand_id, collect())->pluck('name')->all() : []),
                ...$manufacturers->get($product->id, collect())->pluck('name')->all(),
            ]);

            if ($document === null) {
                $records[(int) $product->id] = null;

                continue;
            }

            $records[(int) $product->id] = [
                'document' => $document,
                'engine' => [
                    'id' => (int) $product->id,
                    'codes' => SearchIndex::text($product->sku, $product->barcode, $barcodes, $synonymId ? $synonymSkus->get($synonymId) : null),
                    'names' => SearchIndex::text($productNames),
                    'document' => trim($document),
                    'is_active' => (bool) $product->is_active,
                    'pricing_type' => $product->pricing_type,
                    'item_type' => $product->item_type,
                    'category_ids' => array_values(array_unique([
                        ...$categoryIds->get($product->id, []),
                        ...($product->main_product_id ? $categoryIds->get($product->main_product_id, []) : []),
                    ])),
                ],
            ];
        }

        return $records;
    }

    /**
     * مستند كل تصنيف: نص فهرس قاعدة البيانات (search_text)، ومستند محرك البحث. null للمحذوف.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{document: string, engine: array<string, mixed>}|null>
     */
    private function categoryRecords(array $ids): array
    {
        $prefix = config('items.table_prefix');

        $names = DB::table($prefix.'category_translations')
            ->whereIn('category_id', $ids)
            ->get(['category_id', 'name'])
            ->groupBy('category_id');

        return DB::table($prefix.'categories')
            ->whereIn('id', $ids)
            ->get(['id', 'code', 'slug', 'deleted_at'])
            ->mapWithKeys(function (object $category) use ($names): array {
                $categoryNames = $names->get($category->id, collect())->pluck('name')->all();
                $document = $category->deleted_at !== null ? null : $this->document([
                    ...$categoryNames,
                    ...$this->codeTexts($category->code),
                ]);

                return [(int) $category->id => $document === null ? null : [
                    'document' => $document,
                    'engine' => [
                        'id' => (int) $category->id,
                        'code' => SearchIndex::text($category->code),
                        'names' => SearchIndex::text($categoryNames),
                        'document' => trim($document),
                    ],
                ]];
            })
            ->all();
    }

    /**
     * الرمز كما هو، وملتصقاً بلا فواصل (D-2000 ← d2000).
     *
     * @return array<int, string|null>
     */
    private function codeTexts(?string $code): array
    {
        if (blank($code)) {
            return [];
        }

        $compact = TextNormalizer::compact($code);

        return $compact === TextNormalizer::normalize($code) ? [$code] : [$code, $compact];
    }

    /**
     * @param  array<int, string|null>  $texts
     */
    private function document(array $texts): ?string
    {
        $words = [];

        foreach ($texts as $text) {
            foreach (TextNormalizer::indexWords($text) as $word) {
                $words[$word] = true;
            }
        }

        if ($words === []) {
            return null;
        }

        $document = '';

        foreach (array_keys($words) as $word) {
            if (mb_strlen($document) + mb_strlen((string) $word) + 1 > self::DOCUMENT_MAX_LENGTH) {
                break;
            }

            $document .= ' '.$word;
        }

        return $document.' ';
    }

    /**
     * مستندات الاصناف: تُدرج او تُحدَّث دفعة واحدة، ويُحذف مستند ما لا يُبحث فيه
     * (المحذوف، والمتفرع، وما لم يعد موجوداً).
     *
     * @param  array<int, int>  $ids
     * @param  array<int, string|null>  $documents
     */
    private function writeProducts(array $ids, array $documents): void
    {
        $table = SearchCapabilities::productTable();
        $documents = array_filter($documents);
        $rows = [];

        foreach ($documents as $id => $document) {
            $rows[] = ['product_id' => $id, SearchCapabilities::DOCUMENT => $document];
        }

        $removed = array_values(array_diff($ids, array_keys($documents)));

        if ($removed !== []) {
            DB::table($table)->whereIn('product_id', $removed)->delete();
        }

        if ($rows !== []) {
            DB::table($table)->upsert($rows, ['product_id'], [SearchCapabilities::DOCUMENT]);
        }
    }

    /**
     * مستندات التصنيفات بتحديث واحد، بربط الجدول بجدول مشتق من (المعرّف، المستند).
     *
     * @param  array<int, string|null>  $documents
     */
    private function writeCategories(array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $rows = [];
        $bindings = [];
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        foreach ($documents as $id => $document) {
            $rows[] = match (true) {
                ! $mysql => 'SELECT CAST(? AS BIGINT), CAST(? AS TEXT)',
                $rows === [] => 'SELECT ? AS `id`, ? AS `document`',
                default => 'SELECT ?, ?',
            };
            $bindings[] = $id;
            $bindings[] = $document;
        }

        $table = DB::getTablePrefix().config('items.table_prefix').'categories';
        $column = SearchCapabilities::CATEGORY_COLUMN;

        if (! $mysql) {
            // صيغة PostgreSQL: UPDATE ... FROM بدل UPDATE ... JOIN.
            DB::update(
                "UPDATE \"{$table}\" AS target SET \"{$column}\" = documents.document FROM ("
                .implode(' UNION ALL ', $rows).') AS documents (id, document) WHERE documents.id = target.id',
                $bindings
            );

            return;
        }

        DB::update(
            "UPDATE `{$table}` AS `target` JOIN (".implode(' UNION ALL ', $rows).') AS `documents` ON `documents`.`id` = `target`.`id` '
            ."SET `target`.`{$column}` = `documents`.`document`",
            $bindings
        );
    }

    /**
     * @param  array<int, int>  $values
     * @return array<int, int>
     */
    private function pluckIds(string $table, string $column, string $whereColumn, array $values): array
    {
        $ids = [];

        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            DB::table($table)->whereIn($whereColumn, $chunk)->distinct()->pluck($column)
                ->each(function ($id) use (&$ids): void {
                    $ids[] = (int) $id;
                });
        }

        return $ids;
    }

    private function mark(string $type, mixed $id): void
    {
        if (filled($id) && (int) $id > 0) {
            $this->pending[$type][(int) $id] = true;
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
    }
}
