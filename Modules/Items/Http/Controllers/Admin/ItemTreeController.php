<?php

namespace Modules\Items\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Http\Requests\Admin\ItemTreeCardRequest;
use Modules\Items\Http\Requests\Admin\ItemTreeCategoryRequest;
use Modules\Items\Repositories\Contracts\ItemTreeRepositoryInterface;
use Modules\Items\Services\ItemCardService;
use Modules\Items\Services\ItemTreeService;
use Modules\Items\Support\CategorySearch;
use Modules\Items\Support\ProductSearch;
use Modules\Items\Support\Search\SearchFilters;

/**
 * شجرة الاصناف كما في النظام القديم (products.tree): الشجرة تُحمَّل عند الطلب من مسارات JSON،
 * وجدول العقدة المختارة، والبحث، وبطاقة الصنف وبطاقة الفئة، وقائمة الزر الايمن.
 *
 * كل عملية في القائمة لها مسار بصلاحيتها (الواجهة تخفي ما لا يُسمح به فقط)، والنوع يُفحص
 * هنا ايضاً: النقل والنسخ لا يُقبلان لعقدة ليست من نوعهما.
 */
class ItemTreeController extends Controller
{
    public function __construct(
        private readonly ItemTreeRepositoryInterface $tree,
        private readonly ItemTreeService $treeService,
        private readonly ItemCardService $cards,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Items/ItemTree/Index', [
            'roots' => fn () => $this->tree->roots(),
            // البحث وتصفيته في رابط الصفحة، فتُحفظ عند التحديث وتُشارَك.
            'filters' => [
                'q' => (string) $request->query('q', ''),
                'type' => in_array($request->query('type'), ['simple', 'weight'], true) ? $request->query('type') : '',
                'status' => in_array($request->query('status'), ['0', '1'], true) ? $request->query('status') : '',
            ],
            'pageSize' => ItemTreeRepositoryInterface::PAGE,
            // ما حُفظ في الطلب السابق (بطاقة صنف او فئة)، لتعرضه الشجرة.
            'saved' => fn () => $request->session()->get('tree_saved'),
        ]);
    }

    /* ---------- قراءات الشجرة والجدول ---------- */

    public function children(Request $request, int $category): JsonResponse
    {
        return response()->json($this->tree->children($category, $this->limit($request)));
    }

    public function pieces(int $product): JsonResponse
    {
        return response()->json($this->tree->pieces($product));
    }

    public function path(int $category): JsonResponse
    {
        return response()->json($this->tree->categoryPath($category));
    }

    /**
     * جدول العقدة (?category= او ?product= للصنف الرئيسي)، او نتائج البحث (?q= مع type و status
     * و inside للبحث داخل الفئة المختارة وفروعها).
     */
    public function rows(Request $request): JsonResponse
    {
        $limit = $this->limit($request);
        $search = trim((string) $request->query('q', ''));
        $category = $request->integer('category') ?: null;

        if ($search !== '') {
            $filters = new SearchFilters(
                pricingType: in_array($request->query('type'), ['simple', 'weight'], true) ? $request->query('type') : null,
                active: in_array($request->query('status'), ['0', '1'], true) ? $request->query('status') === '1' : null,
                categoryIds: $request->boolean('inside') && $category ? CategorySearch::withDescendantIds([$category]) : null,
            );

            return response()->json($this->tree->searchRows($search, $filters, $limit));
        }

        return response()->json([
            ...$this->tree->nodeRows($category, $request->integer('product') ?: null, $limit),
            'summary' => null,
        ]);
    }

    public function quantity(int $product): JsonResponse
    {
        return response()->json($this->tree->quantityRows($product));
    }

    /**
     * اوامر قائمة الزر الايمن للعقدة (?kind=root|category|product&id=)، ونوعها ومعرّفاتها.
     */
    public function actions(Request $request): JsonResponse
    {
        $kind = (string) $request->query('kind');
        $node = in_array($kind, ['root', 'category', 'product'], true) ? $this->treeService->node($kind, $request->integer('id') ?: null) : null;

        abort_unless($node !== null, 404);

        $named = $node['category'] ?? $node['product'];

        return response()->json([
            'type' => $node['type'],
            'category_id' => $node['category']?->id,
            'product_id' => $node['product']?->id,
            // رقم العقدة واسمها، لعنوان النوافذ (النقل، الفئة الاب للفئة الجديدة).
            'code' => $node['category']?->code ?? $node['product']?->sku,
            'name' => $named ? (string) ($named->name ?: $named->slug) : null,
            'actions' => $this->treeService->nodeActions($node, $request->user()),
        ]);
    }

    /**
     * اختيار صنف في البطاقة (?q=&type=weight&except=).
     */
    public function lookup(Request $request): JsonResponse
    {
        $options = ProductSearch::options((string) $request->query('q', ''), 25, $request->query('type') === 'weight' ? 'weight' : null);
        unset($options[$request->integer('except')]);

        return response()->json(collect($options)->map(fn (string $label, int $id): array => ['value' => $id, 'label' => $label])->values());
    }

    public function moveTargets(Request $request): JsonResponse
    {
        return response()->json($this->tree->moveTargets((string) $request->query('q', '')));
    }

    /* ---------- بطاقة الصنف ---------- */

    /**
     * بطاقة صنف جديد بسياق الشجرة: الفئة المختارة، الصنف الرئيسي المعروض، الصف المحدد، او
     * الصنف الرئيسي الثابت لاضافة مقطع.
     */
    public function createItem(Request $request): JsonResponse
    {
        $fixedMain = $request->integer('fixed_main') ?: null;

        if ($fixedMain) {
            $main = Product::query()->findOrFail($fixedMain);
            abort_unless($this->treeService->isMain($main), 422);
        }

        return response()->json($this->cards->create(
            $request->integer('category') ?: null,
            $request->integer('product') ?: null,
            $request->integer('highlighted') ?: null,
            $fixedMain,
        ));
    }

    public function editItem(int $product): JsonResponse
    {
        return response()->json($this->cards->edit(Product::query()->findOrFail($product)));
    }

    public function storeItem(ItemTreeCardRequest $request): RedirectResponse
    {
        $product = $this->cards->save(
            null,
            $request->state(),
            $request->integer('category_id') ?: null,
            $request->integer('fixed_main_id') ?: null,
            $request->file('image'),
        );

        return $this->itemSaved($product);
    }

    public function updateItem(ItemTreeCardRequest $request, int $product): RedirectResponse
    {
        $saved = $this->cards->save(
            Product::query()->findOrFail($product),
            $request->state(),
            $request->integer('category_id') ?: null,
            null,
            $request->file('image'),
            $request->boolean('remove_image'),
        );

        return $this->itemSaved($saved);
    }

    /* ---------- بطاقة الفئة ---------- */

    public function showCategory(int $category): JsonResponse
    {
        $model = Category::query()->with('translations')->findOrFail($category);
        $parent = $model->parent_id ? Category::query()->with('translations')->find($model->parent_id) : null;

        return response()->json([
            'id' => (int) $model->id,
            'code' => $model->code,
            'name' => (string) $model->name,
            'parent' => $parent ? ['code' => $parent->code, 'name' => (string) ($parent->name ?: $parent->slug)] : null,
        ]);
    }

    public function storeCategory(ItemTreeCategoryRequest $request): RedirectResponse
    {
        $category = $this->treeService->saveCategory(null, $request->validated('name'), $request->integer('parent_id') ?: null);

        return $this->categorySaved($category);
    }

    public function updateCategory(ItemTreeCategoryRequest $request, int $category): RedirectResponse
    {
        $saved = $this->treeService->saveCategory(Category::query()->findOrFail($category), $request->validated('name'));

        return $this->categorySaved($saved);
    }

    /* ---------- اوامر القائمة ---------- */

    /**
     * نسخ الصنف الرئيسي بمقاطعه: صنف رئيسي برقم جديد ومقاطع بارقام جديدة وبالباركودات نفسها.
     */
    public function copy(Request $request, int $product): RedirectResponse
    {
        $node = $this->treeService->node('product', $product);

        abort_unless($node && $this->treeService->allows('copy', $node, $request->user()), 403);

        return $this->attempt(function () use ($node): string {
            $copy = $this->treeService->copyMain($node['product']);

            return __('Item copied with number :number', ['number' => $copy->sku]);
        });
    }

    public function moveItem(Request $request, int $product): RedirectResponse
    {
        $node = $this->treeService->node('product', $product);

        abort_unless($node && $this->treeService->allows('move', $node, $request->user()), 403);

        $target = $request->integer('target_id') ? Category::query()->find($request->integer('target_id')) : null;

        if (! $target) {
            return back()->with('error', __('Choose the destination category.'));
        }

        return $this->attempt(function () use ($node, $target): string {
            $this->treeService->moveProduct($node['product'], $target);

            return __('Moved successfully');
        });
    }

    /**
     * نقل فئة بما تحتها. target_id = 0 للجذر.
     */
    public function moveCategory(Request $request, int $category): RedirectResponse
    {
        $node = $this->treeService->node('category', $category);

        abort_unless($node && $this->treeService->allows('move', $node, $request->user()), 403);

        $targetId = $request->input('target_id');
        $target = filled($targetId) && (int) $targetId > 0 ? Category::query()->find((int) $targetId) : null;

        if (! $target && ! (filled($targetId) && (int) $targetId === 0)) {
            return back()->with('error', __('Choose the destination category.'));
        }

        return $this->attempt(function () use ($node, $target): string {
            $this->treeService->moveCategory($node['category'], $target);

            return __('Moved successfully');
        });
    }

    /**
     * حذف صنف كما في النظام القديم: له سطور مستندات بلا رصيد ← يُعطَّل بدل الحذف.
     */
    public function destroyItem(int $product): RedirectResponse
    {
        $model = Product::query()->findOrFail($product);

        return $this->attempt(fn (): string => $this->treeService->removeProduct($model) === 'deactivated'
            ? __('The item has document lines, so it was deactivated.')
            : __('Item deleted successfully'));
    }

    /**
     * حذف فئة فارغة. الفئة التي لها توأم صنف رئيسي تُحذف بحذف توأمها (صلاحية حذف الصنف).
     */
    public function destroyCategory(Request $request, int $category): RedirectResponse
    {
        $model = Category::query()->findOrFail($category);

        if ($twin = $this->treeService->twinOf($model)) {
            abort_unless($request->user()?->can('Product Delete'), 403);

            return $this->destroyItem((int) $twin->id);
        }

        return $this->attempt(function () use ($model): string {
            $this->treeService->removeCategory($model);

            return __('Category deleted successfully');
        });
    }

    /* ---------- داخلي ---------- */

    private function limit(Request $request): int
    {
        $limit = $request->integer('limit', ItemTreeRepositoryInterface::PAGE);

        return max(ItemTreeRepositoryInterface::PAGE, min($limit, 1000));
    }

    private function itemSaved(Product $product): RedirectResponse
    {
        return back()
            ->with('success', __('Item saved successfully'))
            ->with('tree_saved', [
                'kind' => 'product',
                'id' => (int) $product->id,
                'main_product_id' => $product->main_product_id ? (int) $product->main_product_id : null,
                'key' => uniqid('', true),
            ]);
    }

    private function categorySaved(Category $category): RedirectResponse
    {
        return back()
            ->with('success', __('Category saved successfully'))
            ->with('tree_saved', [
                'kind' => 'category',
                'id' => (int) $category->id,
                'path' => $this->tree->categoryPath((int) $category->id),
                'key' => uniqid('', true),
            ]);
    }

    /**
     * @param  \Closure(): string  $action  تعيد رسالة النجاح
     */
    private function attempt(\Closure $action): RedirectResponse
    {
        try {
            return back()->with('success', $action());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }
}
