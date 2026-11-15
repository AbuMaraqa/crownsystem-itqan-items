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
use Modules\Core\Support\Translations;
use Modules\Items\Entities\Category;
use Modules\Items\Enums\CategoryStatus;
use Modules\Items\Http\Requests\Admin\CategoryRequest;
use Modules\Items\Repositories\Contracts\CategoryRepositoryInterface;
use Modules\Items\Services\CategoryService;
use Modules\Items\Support\RecordInfo;
use Modules\Items\Support\TranslatedSort;
use Spatie\QueryBuilder\AllowedSort;

/**
 * التصنيفات في لوحة Vue. شجرة التصنيفات واصناف التصنيف (categories.tree, categories.products)
 * يتولاهما CategoryTreeController.
 */
class CategoryController extends Controller
{
    private const TRANSLATED = ['name', 'description'];

    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
        private readonly CategoryService $categoryService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Items/Category/Index', [
            'table' => AdminTable::for($this->categories->tableQuery())
                ->search(['slug', 'code'])
                ->filters(
                    AdminFilter::translated('name'),
                    AdminFilter::exact('parent_id'),
                    AdminFilter::exact('status'),
                    ...AdminFilter::dateRange('created_at'),
                )
                ->sorts(AllowedSort::custom('name', new TranslatedSort), 'code', 'slug', 'id')
                ->defaultSort('code')
                ->rows(fn (Category $category) => [
                    'id' => $category->id,
                    'image' => $category->image ?: null,
                    'name' => (string) $category->name,
                    'code' => $category->code,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'parent' => $category->parent?->name,
                    'active' => $category->status === CategoryStatus::ACTIVE,
                    'locales' => $category->translations->pluck('locale'),
                ]),
            'parents' => fn () => collect($this->categories->parentOptions())
                ->map(fn (string $name, int $id) => ['value' => $id, 'label' => $name])
                ->values()
                ->all(),
        ]);
    }

    public function add(): Response
    {
        return Inertia::render('Items/Category/Add', [
            'parents' => $this->categories->parentTree(),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->categoryService->create(
            $this->attributes($data),
            Translations::fromForm($data, app()->getLocale(), self::TRANSLATED),
            $request->file('image'),
            $request->user()->id,
        );

        return to_route('categories.index')->with('success', __('Category created successfully'));
    }

    public function edit(int $category): Response
    {
        /** @var Category $model */
        $model = $this->categories->query()->with(['translations', 'media'])->findOrFail($category);
        $media = $model->getFirstMedia('category');

        return Inertia::render('Items/Category/Edit', [
            'category' => [
                'id' => $model->id,
                'code' => $model->code,
                'name' => $model->translate(app()->getLocale(), false)?->name ?? '',
                'description' => $model->translate(app()->getLocale(), false)?->description ?? '',
                'slug' => $model->slug,
                'parent_id' => $model->parent_id,
            ],
            'images' => $media ? [['id' => $media->id, 'url' => $media->getUrl()]] : [],
            'parents' => $this->categories->parentTree($model),
        ]);
    }

    public function update(CategoryRequest $request, int $category): RedirectResponse
    {
        /** @var Category $model */
        $model = $this->categories->findOrFail($category);
        $data = $request->validated();

        try {
            $this->categoryService->update(
                $model,
                $this->attributes($data),
                Translations::fromForm($data, app()->getLocale(), self::TRANSLATED),
                $request->file('image'),
            );
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('categories.index')->with('success', __('Category updated successfully'));
    }

    public function destroy(int $category): RedirectResponse
    {
        /** @var Category $model */
        $model = $this->categories->findOrFail($category);

        try {
            $this->categoryService->delete($model);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Category deleted successfully'));
    }

    public function destroyMany(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];

        if ($this->categoryService->deleteMany($ids) > 0) {
            return back()->with('error', __('Some categories were not deleted because they still have subcategories or items.'));
        }

        return back()->with('success', __('Categories deleted successfully'));
    }

    public function info(int $category): JsonResponse
    {
        return response()->json(RecordInfo::of($this->categories->findOrFail($category)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'slug' => $data['slug'],
            'parent_id' => $data['parent_id'] ?? null,
        ];
    }
}
