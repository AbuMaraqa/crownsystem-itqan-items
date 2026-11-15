<?php

namespace Modules\Items\Support\Search;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * فهرس البحث في الاصناف والتصنيفات: لكل صنف مستند (في جدول items_product_search)
 * ولكل تصنيف مستند (في عمود search_text)، فيه كلماته موحَّدة ومحاطة بمسافتين
 * ليُطابَق اول الكلمة بـ LIKE '% كلمة%'.
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

        if (! SearchCapabilities::hasProductIndex()) {
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
        }

        $this->refreshProducts(array_values(array_unique($productIds)));
    }

    /**
     * اعادة بناء الفهرس كله، دفعة بعد دفعة.
     *
     * @param  (Closure(int): void)|null  $progress  يُستدعى بعدد الاصناف المفهرسة بعد كل دفعة
     * @return int عدد الاصناف المفهرسة
     */
    public function rebuild(?Closure $progress = null, int $chunk = self::CHUNK): int
    {
        if (! SearchCapabilities::hasProductIndex()) {
            return 0;
        }

        $prefix = config('items.table_prefix');

        if (SearchCapabilities::hasCategoryIndex()) {
            DB::table($prefix.'categories')->select('id')->orderBy('id')
                ->chunkById($chunk, fn (Collection $rows) => $this->refreshCategories($rows->pluck('id')->all()));
        }

        $count = 0;

        DB::table($prefix.'products')->select('id')->orderBy('id')
            ->chunkById($chunk, function (Collection $rows) use (&$count, $progress): void {
                $this->refreshProducts($rows->pluck('id')->all());
                $count += $rows->count();

                if ($progress) {
                    $progress($count);
                }
            });

        return $count;
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    public function refreshProducts(array $ids): void
    {
        foreach (array_chunk($this->normalizeIds($ids), self::CHUNK) as $chunk) {
            $this->writeProducts($chunk, $this->productDocuments($chunk));
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    public function refreshCategories(array $ids): void
    {
        if (! SearchCapabilities::hasCategoryIndex()) {
            return;
        }

        foreach (array_chunk($this->normalizeIds($ids), self::CHUNK) as $chunk) {
            $this->writeCategories($this->categoryDocuments($chunk));
        }
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string|null>
     */
    private function productDocuments(array $ids): array
    {
        $prefix = config('items.table_prefix');

        $products = DB::table($prefix.'products')
            ->whereIn('id', $ids)
            ->get(['id', 'sku', 'barcode', 'shelf', 'parent_id', 'deleted_at', 'synonym_product_id', 'brand_id']);

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

        $documents = [];

        foreach ($products as $product) {
            if ($product->parent_id !== null || $product->deleted_at !== null) {
                $documents[(int) $product->id] = null;

                continue;
            }

            $synonymId = $product->synonym_product_id;
            $productCategories = $categories->get($product->id, collect());

            // الترتيب بحسب الاهمية، فإن طال المستند سقط آخره.
            $documents[(int) $product->id] = $this->document([
                ...$names->get($product->id, collect())->pluck('name')->all(),
                ...$this->codeTexts($product->sku),
                ...$this->codeTexts($product->barcode),
                ...$secondaryBarcodes->get($product->id, collect())->flatMap(fn (object $row): array => $this->codeTexts($row->barcode))->all(),
                $product->shelf,
                ...($synonymId ? $names->get($synonymId, collect())->pluck('name')->all() : []),
                ...($synonymId ? $this->codeTexts($synonymSkus->get($synonymId)) : []),
                ...$productCategories->pluck('name')->all(),
                ...$productCategories->pluck('code')->all(),
                ...($product->brand_id ? $brands->get($product->brand_id, collect())->pluck('name')->all() : []),
                ...$manufacturers->get($product->id, collect())->pluck('name')->all(),
            ]);
        }

        return $documents;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string|null>
     */
    private function categoryDocuments(array $ids): array
    {
        $prefix = config('items.table_prefix');

        $names = DB::table($prefix.'category_translations')
            ->whereIn('category_id', $ids)
            ->get(['category_id', 'name'])
            ->groupBy('category_id');

        return DB::table($prefix.'categories')
            ->whereIn('id', $ids)
            ->get(['id', 'code', 'slug', 'deleted_at'])
            ->mapWithKeys(fn (object $category): array => [
                (int) $category->id => $category->deleted_at !== null ? null : $this->document([
                    ...$names->get($category->id, collect())->pluck('name')->all(),
                    ...$this->codeTexts($category->code),
                ]),
            ])
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

        foreach ($documents as $id => $document) {
            $rows[] = $rows === [] ? 'SELECT ? AS `id`, ? AS `document`' : 'SELECT ?, ?';
            $bindings[] = $id;
            $bindings[] = $document;
        }

        $table = DB::getTablePrefix().config('items.table_prefix').'categories';
        $column = SearchCapabilities::CATEGORY_COLUMN;

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
