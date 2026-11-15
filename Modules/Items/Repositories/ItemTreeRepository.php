<?php

namespace Modules\Items\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Repositories\BaseEloquentRepository;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Repositories\Contracts\ItemTreeRepositoryInterface;
use Modules\Items\Services\ItemTreeService;
use Modules\Items\Support\CategorySearch;
use Modules\Items\Support\ProductSearch;
use Modules\Items\Support\Search\SearchFilters;
use Modules\Items\Support\Search\SearchHighlighter;
use Modules\Items\Support\Search\SearchQuery;
use Modules\Items\Support\Search\TextNormalizer;

/**
 * قراءات شجرة الاصناف كما في النظام القديم: الشجرة تُحمَّل عند الطلب (لا تُجلب فروع فئة ولا
 * اصنافها الا عند فتحها)، وجدول العقدة المختارة (العقدة نفسها صف داكن ثم ابناؤها)، والبحث.
 *
 * الفئة التي لها "توأم" (صنف فيها رقمه رقمها) تُعرض معه عقدةً واحدة: مقاطع التوأم ابناء الفئة
 * مباشرة، والتوأم نفسه لا يتكرر تحتها.
 */
class ItemTreeRepository extends BaseEloquentRepository implements ItemTreeRepositoryInterface
{
    public function __construct(Product $model, private readonly ItemTreeService $tree)
    {
        parent::__construct($model);
    }

    public function roots(): array
    {
        return $this->categoryQuery()
            ->whereNull('parent_id')
            ->get()
            ->map(fn (Category $category): array => $this->categoryNode($category))
            ->values()
            ->all();
    }

    public function children(int $categoryId, int $limit): array
    {
        $category = Category::query()->select(['id', 'code'])->find($categoryId);

        if (! $category) {
            return ['categories' => [], 'products' => [], 'has_more' => false];
        }

        $twinId = $this->tree->twinIds([$category])[$category->id] ?? null;
        $products = $this->categoryProductsQuery($category->id, $twinId)->limit($limit + 1)->get();
        $this->flagMainProducts($products);

        return [
            'categories' => $this->categoryQuery()
                ->where('parent_id', $category->id)
                ->get()
                ->map(fn (Category $child): array => $this->categoryNode($child))
                ->values()
                ->all(),
            'products' => $products->take($limit)
                ->map(fn (Product $product): array => [
                    'id' => (int) $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'has_sub_products' => $product->sub_products_count > 0,
                ])
                ->values()
                ->all(),
            'has_more' => $products->count() > $limit,
        ];
    }

    public function pieces(int $mainId): array
    {
        return $this->subProductsQuery($mainId)->get()
            ->map(fn (Product $sub): array => ['id' => (int) $sub->id, 'sku' => $sub->sku, 'name' => $sub->name])
            ->values()
            ->all();
    }

    public function nodeRows(?int $categoryId, ?int $productId, int $limit): array
    {
        if ($productId) {
            $main = Product::query()->with('translations')->find($productId);

            if (! $main) {
                return ['rows' => [], 'has_more' => false];
            }

            $this->flagMainProducts([$main]);
            [$subs, $more] = $this->limited($this->subProductsQuery($main->id), $limit);

            return [
                'rows' => [
                    $this->productRow($main, header: true),
                    ...$subs->map(fn (Product $sub): array => $this->productRow($sub))->all(),
                ],
                'has_more' => $more,
            ];
        }

        $category = $categoryId ? Category::query()->with('translations')->find($categoryId) : null;

        if (! $category) {
            return ['rows' => [], 'has_more' => false];
        }

        $twinId = $this->tree->twinIds([$category])[$category->id] ?? null;
        $twin = $twinId ? Product::query()->with('translations')->find($twinId) : null;
        $this->flagMainProducts([$twin]);

        $children = $this->categoryQuery()->where('parent_id', $category->id)->get();
        [$products, $more] = $this->limited($this->categoryProductsQuery($category->id, $twinId), $limit);

        return [
            'rows' => [
                $twin ? $this->productRow($twin, header: true) : $this->categoryRow($category, header: true),
                ...$children->map(fn (Category $child): array => $this->categoryRow($child))->all(),
                ...$products->map(fn (Product $product): array => $this->productRow($product))->all(),
            ],
            'has_more' => $more,
        ];
    }

    public function searchRows(string $search, SearchFilters $filters, int $limit): array
    {
        $result = ProductSearch::find($search, $limit, $filters);
        $query = $result->query;

        // الفئات لا نوع لها ولا حالة، فلا تظهر مع هاتين التصفيتين.
        $categories = $filters->pricingType === null && $filters->active === null
            ? CategorySearch::search($query, 10)
            : collect();

        if ($filters->categoryIds !== null) {
            $categories = $categories->filter(fn (Category $category): bool => in_array((int) $category->id, $filters->categoryIds, true));
        }

        // التوأم يظهر بفئته، فلا يتكرر صفاً للصنف.
        $twinIds = array_values($this->tree->twinIds($categories));
        $rows = [];

        foreach ($categories as $category) {
            $rows['c'.$category->id] = $this->categoryRow($category, query: $query);
        }

        $ids = array_values(array_diff($result->ids, $twinIds));

        if ($ids !== []) {
            $products = Product::query()
                ->with([
                    'translations:id,product_id,locale,name',
                    'categories.translations',
                    'brand.translations',
                    'manufacturers.translations',
                    'productBarcodes:id,product_id,barcode,sort_order',
                    'synonymProduct' => fn ($query) => $query->select(['id', 'sku'])->with('translations:id,product_id,locale,name'),
                ])
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');

            $mains = Product::query()
                ->with('translations:id,product_id,locale,name')
                ->whereIn('id', $products->pluck('main_product_id')->filter()->unique()->values()->all())
                ->get()
                ->keyBy('id');

            $this->flagMainProducts([...$products->all(), ...$mains->all()]);

            foreach ($ids as $id) {
                $product = $products->get($id);

                if (! $product) {
                    continue;
                }

                $main = $mains->get($product->main_product_id);

                if ($main && ! isset($rows['p'.$main->id])) {
                    $rows['p'.$main->id] = $this->productRow($main, header: true, query: $query, context: ! in_array((int) $main->id, $ids, true));
                }

                $rows['p'.$product->id] ??= $this->productRow($product, indented: (bool) $main, query: $query);
            }
        }

        $rows = array_values($rows);

        return [
            'rows' => $rows,
            'has_more' => $result->hasMore,
            'summary' => [
                'count' => count(array_filter($rows, fn (array $row): bool => ! $row['is_context'])),
                'has_more' => $result->hasMore,
                'approximate' => $result->approximate,
                'corrected_from' => $result->correctedFrom,
                'corrected_to' => $result->correctedFrom !== null ? $query->raw : null,
            ],
        ];
    }

    public function quantityRows(int $productId): array
    {
        $product = Product::query()->with(['translations', 'unit.translations'])->find($productId);

        if (! $product) {
            return [];
        }

        $subItems = Product::query()->with(['translations', 'unit.translations'])->where('main_product_id', $product->id)->orderBy('sku')->get();
        $items = collect([$product])->merge($subItems);

        // الصنف الرئيسي: عدد القطع مجموع مقاطعه، والوزن كغمه هو؛ لا يُجمع مع الوزن المحسوب للمقاطع.
        return $items->map(fn (Product $item): array => [
            'id' => (int) $item->id,
            'sku' => $item->sku,
            'name' => $item->name,
            'quantity' => $item->is($product) && $subItems->isNotEmpty() ? (float) $subItems->sum('stock_quantity') : (float) $item->stock_quantity,
            'weight' => (float) $item->stock_weight,
            'is_total' => $item->is($product) && $subItems->isNotEmpty(),
            'unit' => $item->unit?->name,
            'min_limit_1' => filled($item->min_limit_1) ? (float) $item->min_limit_1 : null,
            'min_limit_2' => filled($item->min_limit_2) ? (float) $item->min_limit_2 : null,
            'max_limit' => filled($item->max_limit) ? (float) $item->max_limit : null,
            // دون الحد الادنى الاول يُنبَّه عليه كما في تقارير النقص.
            'is_low' => (float) $item->min_limit_1 > 0 && (float) $item->stock_quantity <= (float) $item->min_limit_1,
            'is_sub' => $item->id !== $product->id,
        ])->values()->all();
    }

    public function moveTargets(string $search): array
    {
        $options = CategorySearch::options($search, 25);
        $categories = Category::query()->select(['id', 'code'])->whereIn('id', array_keys($options))->get();
        $twins = $this->tree->twinIds($categories);

        $targets = [];

        foreach (array_diff_key($options, $twins) as $id => $label) {
            $targets[] = ['value' => (int) $id, 'label' => (string) $label];
        }

        return $targets;
    }

    public function categoryPath(int $categoryId): array
    {
        $ids = [];
        $cursor = $categoryId;

        for ($depth = 0; $cursor && $depth < 20 && ! in_array($cursor, $ids, true); $depth++) {
            $ids[] = $cursor;
            $cursor = (int) Category::query()->whereKey($cursor)->value('parent_id');
        }

        return array_reverse($ids);
    }

    public function suggestedMainId(?int $highlightedId, ?int $selectedProductId, ?int $selectedCategoryId): ?int
    {
        if ($highlightedId) {
            $product = Product::query()->select(['id', 'main_product_id'])->find($highlightedId);
            $this->flagMainProducts([$product]);

            if ($product?->main_product_id) {
                return (int) $product->main_product_id;
            }

            if ($product && $product->sub_products_count > 0) {
                return (int) $product->id;
            }
        }

        if ($selectedProductId) {
            return $selectedProductId;
        }

        $twinId = $this->tree->twinIdOf($selectedCategoryId);

        return $twinId && Product::query()->whereKey($twinId)->where('pricing_type', 'weight')->exists() ? $twinId : null;
    }

    public function cardParentCategory(?Product $product, ?int $selectedCategoryId): ?Category
    {
        if (! $product) {
            return $selectedCategoryId ? Category::query()->with('translations')->find($selectedCategoryId) : null;
        }

        $category = $product->categories()->with('translations')->first();

        if ($category && filled($category->code) && $category->code === $product->sku) {
            $category = $category->parent_id ? Category::query()->with('translations')->find($category->parent_id) : null;
        }

        return $category;
    }

    /* ---------- داخلي ---------- */

    private function categoryQuery(): Builder
    {
        return Category::query()
            ->with('translations')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('id');
    }

    /**
     * @return array{id: int, code: string|null, name: string}
     */
    private function categoryNode(Category $category): array
    {
        return [
            'id' => (int) $category->id,
            'code' => $category->code,
            'name' => (string) ($category->name ?: $category->slug),
        ];
    }

    /**
     * ابناء الفئة من الاصناف: اصنافها التي ليست فرعية لغيرها، ومقاطع توأمها. التوأم نفسه لا
     * يُعرض لانه الفئة ذاتها.
     */
    private function categoryProductsQuery(int $categoryId, ?int $twinId = null): Builder
    {
        return Product::query()
            ->select(['id', 'sku', 'barcode', 'main_product_id', 'pricing_type', 'is_active'])
            ->with('translations:id,product_id,locale,name')
            ->whereNull('parent_id')
            ->where(function (Builder $query) use ($categoryId, $twinId): void {
                $query->where(fn (Builder $query) => $query
                    ->whereNull('main_product_id')
                    ->whereHas('categories', fn ($query) => $query->where((new Category)->getTable().'.id', $categoryId)));

                if ($twinId) {
                    $query->orWhere('main_product_id', $twinId);
                }
            })
            ->when($twinId, fn (Builder $query) => $query->whereKeyNot($twinId))
            ->orderBy('sku');
    }

    private function subProductsQuery(int $mainProductId): Builder
    {
        return Product::query()
            ->select(['id', 'sku', 'barcode', 'main_product_id', 'pricing_type', 'is_active'])
            ->with('translations:id,product_id,locale,name')
            ->where('main_product_id', $mainProductId)
            ->orderBy('sku');
    }

    /**
     * @return array{0: Collection<int, Product>, 1: bool}
     */
    private function limited(Builder $query, int $limit): array
    {
        $products = $query->limit($limit + 1)->get();
        $this->flagMainProducts($products);

        return [$products->take($limit), $products->count() > $limit];
    }

    /**
     * يعلّم الاصناف الرئيسية بينها (sub_products_count) باستعلام واحد بقائمة المعرّفات. لا
     * withCount('subProducts'): يعدّ لكل صف باستعلام فرعي يمر على جدول الاصناف كله.
     *
     * @param  iterable<Product|null>  $products
     */
    private function flagMainProducts(iterable $products): void
    {
        $products = collect($products)->filter();
        $mainIds = ProductSearch::idsWithSubProducts($products->map(fn (Product $product): int => (int) $product->id)->values()->all());

        $products->each(fn (Product $product) => $product->setAttribute('sub_products_count', in_array((int) $product->id, $mainIds, true) ? 1 : 0));
    }

    /**
     * @param  bool  $context  صف للسياق فقط (الصنف الرئيسي لصنف فرعي طابق)، لا يُعدّ نتيجة
     * @return array<string, mixed>
     */
    private function productRow(Product $product, bool $header = false, bool $indented = false, ?SearchQuery $query = null, bool $context = false): array
    {
        return [
            'key' => 'p'.$product->id,
            'kind' => 'product',
            'id' => (int) $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'main_product_id' => $product->main_product_id ? (int) $product->main_product_id : null,
            'is_header' => $header,
            'is_main' => (int) ($product->sub_products_count ?? 0) > 0,
            'is_sub' => $indented,
            'is_context' => $context,
            'is_inactive' => $query !== null && (int) $product->is_active === 0,
            'html' => $query ? $this->highlightedCells($product->sku, $product->barcode, $product->name, $query) : null,
            'matched_in' => $query && ! $context ? $this->matchedIn($product, $query) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryRow(Category $category, bool $header = false, ?SearchQuery $query = null): array
    {
        $name = $category->name ?: $category->slug;

        return [
            'key' => 'c'.$category->id,
            'kind' => 'category',
            'id' => (int) $category->id,
            'sku' => $category->code,
            'barcode' => null,
            'name' => $name,
            'main_product_id' => null,
            'is_header' => $header,
            'is_main' => false,
            'is_sub' => false,
            'is_context' => false,
            'is_inactive' => false,
            'html' => $query ? $this->highlightedCells($category->code, null, $name, $query) : null,
            'matched_in' => null,
        ];
    }

    /**
     * HTML آمن من SearchHighlighter (النص مُهرَّب وما طابق داخل <mark>).
     *
     * @return array{sku: string, barcode: string, name: string}
     */
    private function highlightedCells(?string $sku, ?string $barcode, ?string $name, SearchQuery $query): array
    {
        return [
            'sku' => (string) SearchHighlighter::highlight($sku, $query),
            'barcode' => (string) SearchHighlighter::highlight($barcode, $query),
            'name' => (string) SearchHighlighter::highlight($name, $query),
        ];
    }

    /**
     * الحقل الذي طابقت فيه كلمات البحث اذا لم تكن كلها في الاسم او الرقم او الباركود، ليعرف
     * المستخدم لماذا ظهر الصنف.
     */
    private function matchedIn(Product $product, SearchQuery $query): ?string
    {
        $visible = ' '.implode(' ', TextNormalizer::indexWords($product->name.' '.$product->sku.' '.$product->barcode)).' ';
        $missing = array_filter($query->terms, fn (string $term): bool => ! $this->containsTerm($visible, $term, $query));

        if ($missing === []) {
            return null;
        }

        $sources = [
            __('Barcode') => $product->productBarcodes->pluck('barcode')->all(),
            __('Category') => $product->categories->map(fn (Category $category): string => trim($category->name.' '.$category->code))->all(),
            __('Brand') => [$product->brand?->name],
            __('Manufacturer') => $product->manufacturers->pluck('name')->all(),
            __('Synonym Item') => [$product->synonymProduct ? trim($product->synonymProduct->sku.' '.$product->synonymProduct->name) : null],
            __('Shelf') => [$product->shelf],
        ];

        foreach ($sources as $label => $texts) {
            foreach (array_filter($texts) as $text) {
                $words = ' '.implode(' ', TextNormalizer::indexWords($text)).' ';

                foreach ($missing as $term) {
                    if ($this->containsTerm($words, $term, $query)) {
                        return $label.': '.$text;
                    }
                }
            }
        }

        return null;
    }

    private function containsTerm(string $words, string $term, SearchQuery $query): bool
    {
        foreach ($query->variants($term) as $variant) {
            if (str_contains($words, ' '.$variant)) {
                return true;
            }
        }

        return false;
    }
}
