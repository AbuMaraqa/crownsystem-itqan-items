<?php

namespace Modules\Items\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Items\Entities\Addon;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ItemType;
use Modules\Items\Enums\ProductActive;
use Modules\Items\Http\Requests\Admin\ProductRequest;
use Modules\Items\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Items\Services\ItemsPdfService;
use Modules\Items\Services\ProductAccessoryService;
use Modules\Items\Services\ProductService;
use Modules\Items\Support\ItemCardRules;
use Modules\Items\Support\ProductSearch;
use Modules\Items\Support\ProductStockSummary;
use Modules\Items\Support\RecordInfo;
use Modules\Items\Support\Search\SearchHighlighter;
use Modules\Items\Support\Search\SearchQuery;
use Modules\Items\Support\Search\TextNormalizer;
use Modules\Items\Support\TranslatedSort;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

/**
 * بطاقة الصنف في لوحة Vue: قائمة الاصناف واضافة الصنف وتعديله. شجرة الاصناف (products.tree) يتولاها
 * ItemTreeController، ووحدات الصنف واسعاره واسعار الفروع لها متحكماتها.
 */
class ProductController extends Controller
{
    /** الاصناف الفرعية الظاهرة في صف صنفها الرئيسي، والباقي يُختصر بعدد. */
    private const VISIBLE_SUB_ITEMS = 5;

    /** ملصقات ملف PDF واحد. */
    private const MAX_LABELS = 1000;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductService $productService,
    ) {}

    public function index(Request $request): Response
    {
        $filter = (array) $request->input('filter', []);
        $search = SearchQuery::from((string) ($filter['search'] ?? $filter['name'] ?? ''));
        // مع البحث وبلا ترتيب يختاره المستخدم: الاقرب الى نص البحث اولاً.
        $byRelevance = ! $request->filled('sort');

        return Inertia::render('Items/Product/Index', [
            'table' => AdminTable::for($this->products->tableQuery())
                ->filters(
                    AllowedFilter::callback('search', function (Builder $query, mixed $value) use ($byRelevance): void {
                        $this->products->searchWithSubProducts($query, (string) $value);

                        if ($byRelevance) {
                            ProductSearch::orderByRelevance($query, (string) $value);
                        }
                    }),
                    AllowedFilter::callback('name', fn (Builder $query, mixed $value) => $this->products->searchWithSubProducts($query, (string) $value)),
                    AllowedFilter::callback('category_id', fn (Builder $query, mixed $value) => $this->products->inCategory($query, (int) $value)),
                    AllowedFilter::callback('pricing_type', function (Builder $query, mixed $value): void {
                        if (in_array($value, ['simple', 'weight'], true)) {
                            $query->where('pricing_type', $value);
                        }
                    }),
                    AllowedFilter::callback('is_active', fn (Builder $query, mixed $value) => $query->where('is_active', (int) $value)),
                    ...AdminFilter::numberRange('base_price'),
                    ...AdminFilter::dateRange('created_at'),
                )
                ->sorts(AllowedSort::custom('name', new TranslatedSort), 'sku', 'slug', 'is_active', 'base_price', 'id')
                ->rows(fn (Product $product) => [
                    'id' => $product->id,
                    'image' => $product->image ?: null,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'name' => (string) $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description ? strip_tags((string) $product->description) : null,
                    'categories' => $product->categories->pluck('name')->join(', '),
                    'is_active' => (int) $product->is_active === ProductActive::IS_ACTIVE->value,
                    'pricing_type' => $product->pricing_type,
                    'base_price' => $product->base_price,
                    'locales' => $product->translations->pluck('locale')->values(),
                    'sub_items' => $this->subItems($product, $search),
                ]),
            'categories' => fn () => $this->products->formOptions()['categories'],
        ]);
    }

    public function add(): Response
    {
        return Inertia::render('Items/Product/Add', [
            'options' => $this->products->formOptions(),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $this->productService->create($request->forService(), app()->getLocale(), $request->user()->id);

        return to_route('products.index')->with('success', __('Product created successfully'));
    }

    public function edit(int $product, ProductAccessoryService $accessories): Response
    {
        $model = $this->products->findForEdit($product);

        return Inertia::render('Items/Product/Edit', [
            'options' => $this->products->formOptions($model),
            'product' => $this->formState($model, $accessories),
            // تسميات ما اختير في الحقول التي تبحث في الخادم.
            'labels' => ProductSearch::labels([
                $model->main_product_id,
                ...$model->accessories()->pluck('accessory_product_id')->all(),
                ...$model->cuttingRecipesAsSource()->pluck('output_product_id')->all(),
            ]),
            'subProducts' => $this->products->subProductRows($model),
            'madeFrom' => $this->products->madeFrom($model),
            'stock' => ProductStockSummary::of($model),
            'stockLinks' => ProductStockSummary::links($model),
        ]);
    }

    public function update(ProductRequest $request, int $product): RedirectResponse
    {
        /** @var Product $model */
        $model = $this->products->findOrFail($product);

        $this->productService->update($model, $request->forService(), app()->getLocale(), $request->user()->id);

        return to_route('products.index')->with('success', __('Product updated successfully'));
    }

    public function active(Request $request, int $product): RedirectResponse
    {
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];

        /** @var Product $model */
        $model = $this->products->findOrFail($product);

        $this->productService->setActive($model, $active);

        return back()->with('success', $active ? __('Product activated successfully') : __('Product deactivated successfully'));
    }

    public function destroy(int $product): RedirectResponse
    {
        /** @var Product $model */
        $model = $this->products->findOrFail($product);

        try {
            $this->productService->delete($model);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Product deleted successfully'));
    }

    public function destroyMany(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];

        if ($this->productService->deleteMany(array_map('intval', $ids)) > 0) {
            return back()->with('error', __('Some items were not deleted because they have sub-items or are used in documents.'));
        }

        return back()->with('success', __('Products deleted successfully'));
    }

    public function info(int $product): JsonResponse
    {
        return response()->json(RecordInfo::of($this->products->findOrFail($product)));
    }

    /**
     * Pickers of the form: `?type=main|goods|any&exclude=id&q=term`.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:main,goods,any'],
            'exclude' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->products->lookup($data['type'], (string) ($data['q'] ?? ''), isset($data['exclude']) ? (int) $data['exclude'] : null));
    }

    /**
     * The rows of the barcode dialog: the item and its variations, with a preview of each barcode.
     */
    public function barcodes(ItemsPdfService $pdf, int $product): JsonResponse
    {
        $model = $this->products->withVariations($product);

        return response()->json([
            'rows' => collect([$model, ...$model->variations])->map(fn (Product $row) => [
                'id' => $row->id,
                'name' => (string) $row->name,
                'code' => (string) ($row->barcode ?: $row->sku),
                'svg' => filled($row->barcode) ? $pdf->generateBarcodeSvg($row->barcode, 'C128', 1, 40) : '',
            ])->values(),
        ]);
    }

    public function barcodesPdf(Request $request, ItemsPdfService $pdf, int $product): mixed
    {
        $quantities = $request->validate([
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['integer', 'min:0', 'max:'.self::MAX_LABELS],
        ])['quantities'] ?? [];

        $model = $this->products->withVariations($product);
        $total = collect([$model, ...$model->variations])->sum(fn (Product $row) => (int) ($quantities[$row->id] ?? 1));

        abort_if($total < 1 || $total > self::MAX_LABELS, 422, __('Choose between 1 and :max labels.', ['max' => self::MAX_LABELS]));

        return $pdf->generateBarcodeLabels($model, collect($quantities)->mapWithKeys(fn ($quantity, $id) => ['quantity_'.$id => (int) $quantity])->all());
    }

    /**
     * The sub-items under their main item's name. When searching, the matching ones come first and
     * are highlighted; the rest are cut to a count so the row stays short.
     *
     * @return array{count: int, rows: list<array<string, mixed>>, more: int}|null
     */
    private function subItems(Product $product, SearchQuery $search): ?array
    {
        if ($product->subProducts->isEmpty()) {
            return null;
        }

        // المطابقة كالبحث نفسه: كل الكلمات بأي ترتيب، وبلا فرق في الهمزات والتاء المربوطة.
        $matches = function (Product $sub) use ($search): bool {
            $text = TextNormalizer::normalize($sub->name.' '.$sub->sku.' '.$sub->barcode);

            return ! $search->isEmpty() && collect($search->terms)->every(fn (string $term): bool => str_contains($text, $term));
        };

        [$matched, $others] = $product->subProducts->partition($matches);
        $visible = $matched->concat($others)->take(max($matched->count(), self::VISIBLE_SUB_ITEMS));

        return [
            'count' => $product->subProducts->count(),
            'rows' => $visible->map(fn (Product $sub) => [
                'id' => $sub->id,
                // نص مهرَّب، والتمييز وسم <mark> فقط (SearchHighlighter).
                'name_html' => (string) SearchHighlighter::highlight($sub->name, $search),
                'barcode' => $sub->barcode,
                'matched' => $matches($sub),
            ])->values()->all(),
            'more' => $product->subProducts->count() - $visible->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formState(Product $product, ProductAccessoryService $accessories): array
    {
        $images = fn (Product $row, string $collection) => $row->getMedia($collection)
            ->map(fn (Media $media) => ['id' => $media->id, 'url' => $media->getUrl()])->values()->all();
        $overrides = $product->addons->isEmpty() ? collect() : DB::table(config('items.table_prefix').'addon_product_option')
            ->whereIn('addon_product_id', $product->addons->pluck('pivot.id'))
            ->get()
            ->groupBy('addon_product_id');

        return [
            'id' => $product->id,
            ...$this->rowState($product),
            'weight_per_meter' => $product->weight_per_meter,
            'length' => $product->length,
            'pricing_type' => $product->pricing_type ?: 'simple',
            ...ItemCardRules::itemModelFormState($product),
            'item_type' => $product->item_type ?: ItemType::GOODS->value,
            'service_kind' => $product->service_kind,
            'service_cost_source' => $product->service_cost_source,
            'revenue_account_id' => $product->revenue_account_id,
            'cost_account_id' => $product->cost_account_id,
            'main_product_id' => $product->main_product_id,
            'site_area' => $product->site_area,
            'color' => $product->color,
            'brand_id' => $product->brand_id,
            'unit_id' => $product->unit_id,
            'tags' => $product->tags->pluck('name')->filter()->values()->all(),
            'labels' => $product->labels->pluck('name')->filter()->values()->all(),
            'manufacturers' => $product->manufacturers->modelKeys(),
            'branches' => $product->branches->modelKeys(),
            'images' => $images($product, 'product'),
            'gallery_images' => $images($product, 'product_gallery'),
            'addons' => $product->addons->map(fn (Addon $addon) => [
                'addon_id' => $addon->id,
                'is_required' => (bool) $addon->pivot->is_required,
                'min_selection' => $addon->pivot->min_selection,
                'max_selection' => $addon->pivot->max_selection,
                'max_quantity_per_option' => $addon->pivot->max_quantity_per_option,
                'allowed_options' => $overrides->get($addon->pivot->id, collect())->map(fn (object $row) => [
                    'addon_option_id' => $row->addon_option_id,
                    'price' => $row->price,
                    'is_default' => (bool) $row->is_default,
                ])->values()->all(),
            ])->values()->all(),
            'accessories' => $accessories->state($product),
            'cutting_recipes' => $product->cuttingRecipesAsSource()->get()->map(fn ($recipe) => [
                'id' => $recipe->id,
                'output_product_id' => $recipe->output_product_id,
                'pieces_per_unit' => $recipe->pieces_per_unit,
            ])->values()->all(),
            'variations' => $product->variations->map(fn (Product $variation) => [
                'id' => $variation->id,
                ...$this->rowState($variation),
                'attribute_values' => $variation->attributeValues->modelKeys(),
                'images' => $images($variation, 'product'),
                'gallery_images' => $images($variation, 'product_gallery'),
            ])->values()->all(),
            // القيم المختارة لكل خاصية، من المتغيرات المحفوظة.
            'attributes' => (object) $product->variations->flatMap->attributeValues
                ->groupBy('attribute_id')
                ->map(fn ($values) => $values->pluck('id')->unique()->values()->all())
                ->all(),
        ];
    }

    /**
     * What an item and a variation share. Names follow the interface locale with its fallback.
     *
     * @return array<string, mixed>
     */
    private function rowState(Product $product): array
    {
        return [
            'name' => (string) $product->name,
            'slug' => $product->slug,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'base_price' => $product->base_price,
            'max_limit' => $product->max_limit,
            'min_limit_1' => $product->min_limit_1,
            'min_limit_2' => $product->min_limit_2,
            'category_id' => $product->categories->modelKeys(),
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
        ];
    }
}
