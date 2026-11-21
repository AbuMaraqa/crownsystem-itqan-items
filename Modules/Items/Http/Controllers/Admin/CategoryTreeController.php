<?php

namespace Modules\Items\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Repositories\Contracts\CategoryRepositoryInterface;
use Modules\Items\Services\CategoryService;
use Modules\Items\Support\ProductSearch;
use Modules\Items\Support\TranslatedSort;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

/**
 * شجرة التصنيفات (categories.tree) واصناف التصنيف (categories.products).
 *
 * الشجرة تُحمَّل عند الطلب: الجذور مع الصفحة، وفروع التصنيف واصنافه عند فتحه (دفعات من 50
 * صنفاً). عند البحث تُبنى الشجرة كاملة لان النتيجة قد تكون في اي مستوى. السحب يغيّر الاب
 * والترتيب بين الاخوة.
 */
class CategoryTreeController extends Controller
{
    /** دفعة الاصناف الواحدة داخل التصنيف. */
    public const PRODUCTS_PAGE = 50;

    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
        private readonly CategoryService $categoryService,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $showInactive = ! $request->has('show_inactive') || $request->boolean('show_inactive');

        return Inertia::render('Items/CategoryTree/Index', [
            'tree' => fn () => $search !== ''
                ? $this->categories->searchTree($search, $showInactive)
                : $this->categories->treeNodes(null, $showInactive),
            'filters' => ['search' => $search, 'show_inactive' => $showInactive],
            'searching' => $search !== '',
            'pageSize' => self::PRODUCTS_PAGE,
        ]);
    }

    /**
     * فروع التصنيف واصنافه عند فتحه (?limit= لعرض المزيد من الاصناف).
     */
    public function children(Request $request, int $category): JsonResponse
    {
        $limit = max(self::PRODUCTS_PAGE, min($request->integer('limit', self::PRODUCTS_PAGE), 2000));
        $showInactive = ! $request->has('show_inactive') || $request->boolean('show_inactive');

        return response()->json([
            'children' => $this->categories->treeNodes($category, $showInactive),
            'products' => $this->categories->treeProducts($category, $limit),
        ]);
    }

    /**
     * نقل تصنيف بالسحب: اب جديد (null للجذر) وموضع بين اخوته.
     */
    public function move(Request $request, int $category): RedirectResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'position' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $this->categoryService->moveInTree(
                $this->findCategory($category),
                isset($data['parent_id']) ? (int) $data['parent_id'] : null,
                (int) $data['position'],
            );
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Category moved successfully'));
    }

    public function toggleStatus(int $category): RedirectResponse
    {
        $this->categoryService->toggleStatus($this->findCategory($category));

        return back()->with('success', __('Status updated successfully'));
    }

    /**
     * اصناف التصنيف: الاصناف المربوطة به عبر جدول الربط، والمتغيرات معها بصنفها الاب.
     */
    public function products(int $category): Response
    {
        $model = Category::query()->with('translations')->findOrFail($category);

        return Inertia::render('Items/CategoryTree/Products', [
            'category' => ['id' => (int) $model->id, 'name' => (string) $model->name, 'code' => $model->code],
            'table' => AdminTable::for($this->categories->productsQuery($model))
                ->filters(
                    AllowedFilter::callback('search', fn ($query, mixed $value) => ProductSearch::apply($query, (string) $value)),
                    AdminFilter::exact('pricing_type'),
                    AdminFilter::boolean('is_active'),
                    ...AdminFilter::numberRange('base_price'),
                )
                ->sorts(AllowedSort::custom('name', new TranslatedSort), 'sku', 'base_price', 'id')
                ->defaultSort('sku')
                ->withCreator()
                ->rows(fn (Product $product) => [
                    'id' => (int) $product->id,
                    'image' => $product->image ?: null,
                    'name' => (string) $product->name,
                    'sku' => $product->sku,
                    'base_price' => $product->base_price,
                    'categories' => $product->categories->pluck('name')->join(', '),
                    // المتغيرات تُربط بالتصنيفات ايضاً، فيُذكر الصنف الاب لتمييزها.
                    'parent' => $product->parent?->name,
                ]),
        ]);
    }

    private function findCategory(int $id): Category
    {
        /** @var Category */
        return $this->categories->findOrFail($id);
    }
}
