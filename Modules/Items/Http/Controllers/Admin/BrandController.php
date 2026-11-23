<?php

namespace Modules\Items\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Http\Controllers\Concerns\DeletesManyRecords;
use Modules\Core\Http\Controllers\Concerns\ReordersRecords;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\SafeDelete;
use Modules\Items\Entities\Brand;
use Modules\Items\Http\Requests\Admin\BrandRequest;
use Modules\Items\Repositories\Contracts\BrandRepositoryInterface;
use Modules\Items\Services\BrandService;
use Modules\Core\Support\RecordInfo;
use Modules\Items\Support\TranslatedSort;
use Spatie\QueryBuilder\AllowedSort;

class BrandController extends Controller
{
    use DeletesManyRecords;
    use ReordersRecords;

    public function __construct(
        private readonly BrandRepositoryInterface $brands,
        private readonly BrandService $brandService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Items/Brand/Index', [
            'table' => AdminTable::for($this->brands->tableQuery())
                ->search(['slug'])
                ->filters(AdminFilter::translated('name'), ...AdminFilter::dateRange('created_at'))
                ->sorts(AllowedSort::custom('name', new TranslatedSort), 'slug', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(function (Brand $brand) {
                    $media = $brand->getFirstMedia('brand');

                    return [
                        'id' => $brand->id,
                        'image' => $media?->getUrl(),
                        'image_id' => $media?->id,
                        'name' => (string) $brand->name,
                        'slug' => $brand->slug,
                        'description' => $brand->description,
                        'locales' => $brand->translations->pluck('locale'),
                        // The modal edits the current locale only, without the fallback the table shows.
                        'form' => [
                            'name' => $brand->translate(app()->getLocale(), false)?->name ?? '',
                            'description' => $brand->translate(app()->getLocale(), false)?->description ?? '',
                        ],
                    ];
                }),
        ]);
    }

    public function store(BrandRequest $request): RedirectResponse
    {
        $this->brandService->create($request->validated(), $request->file('image'), app()->getLocale(), $request->user()->id);

        return back()->with('success', __('Brand created successfully'));
    }

    public function update(BrandRequest $request, int $brand): RedirectResponse
    {
        /** @var Brand $model */
        $model = $this->brands->query()->with('media')->findOrFail($brand);
        $data = $request->validated();

        $this->brandService->update($model, $data, $request->file('image'), $data['removed_image'] ?? [], app()->getLocale());

        return back()->with('success', __('Brand updated successfully'));
    }

    public function destroy(int $brand): RedirectResponse
    {
        /** @var Brand $model */
        $model = $this->brands->findOrFail($brand);

        try {
            if (! $this->brandService->delete($model)) {
                return back()->with('error', SafeDelete::message());
            }
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Brand deleted successfully'));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->brands->tableQuery();
    }

    /**
     * @param  Brand  $record
     */
    protected function deleteRecord(Model $record): bool
    {
        return $this->brandService->delete($record);
    }

    protected function sortableModel(): string
    {
        return Brand::class;
    }

    public function info(int $brand): JsonResponse
    {
        return response()->json(RecordInfo::of($this->brands->findOrFail($brand)));
    }
}
