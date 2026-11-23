<?php

namespace Modules\Items\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
use Modules\Items\Entities\Manufacturer;
use Modules\Items\Http\Requests\Admin\ManufacturerRequest;
use Modules\Items\Repositories\Contracts\ManufacturerRepositoryInterface;
use Modules\Items\Services\ManufacturerService;
use Modules\Core\Support\RecordInfo;
use Modules\Items\Support\TranslatedSort;
use Spatie\QueryBuilder\AllowedSort;

class ManufacturerController extends Controller
{
    use DeletesManyRecords;
    use ReordersRecords;

    public function __construct(
        private readonly ManufacturerRepositoryInterface $manufacturers,
        private readonly ManufacturerService $manufacturerService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Items/Manufacturer/Index', [
            'table' => AdminTable::for($this->manufacturers->tableQuery())
                ->search(['slug'])
                ->filters(AdminFilter::translated('name'), ...AdminFilter::dateRange('created_at'))
                ->sorts(AllowedSort::custom('name', new TranslatedSort), 'slug', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(function (Manufacturer $manufacturer) {
                    $media = $manufacturer->getFirstMedia('manufacturer');

                    return [
                        'id' => $manufacturer->id,
                        'image' => $media?->getUrl(),
                        'image_id' => $media?->id,
                        'name' => (string) $manufacturer->name,
                        'slug' => $manufacturer->slug,
                        'description' => $manufacturer->description,
                        'locales' => $manufacturer->translations->pluck('locale'),
                        // The modal edits the current locale only, without the fallback the table shows.
                        'form' => [
                            'name' => $manufacturer->translate(app()->getLocale(), false)?->name ?? '',
                            'description' => $manufacturer->translate(app()->getLocale(), false)?->description ?? '',
                        ],
                    ];
                }),
        ]);
    }

    public function store(ManufacturerRequest $request): RedirectResponse
    {
        $this->manufacturerService->create($request->validated(), $request->file('image'), app()->getLocale(), $request->user()->id);

        return back()->with('success', __('Manufacturer created successfully'));
    }

    public function update(ManufacturerRequest $request, int $manufacturer): RedirectResponse
    {
        /** @var Manufacturer $model */
        $model = $this->manufacturers->query()->with('media')->findOrFail($manufacturer);
        $data = $request->validated();

        $this->manufacturerService->update($model, $data, $request->file('image'), $data['removed_image'] ?? [], app()->getLocale());

        return back()->with('success', __('Manufacturer updated successfully'));
    }

    public function destroy(int $manufacturer): RedirectResponse
    {
        /** @var Manufacturer $model */
        $model = $this->manufacturers->findOrFail($manufacturer);

        if (! $this->manufacturerService->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Manufacturer deleted successfully'));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->manufacturers->tableQuery();
    }

    /**
     * @param  Manufacturer  $record
     */
    protected function deleteRecord(Model $record): bool
    {
        return $this->manufacturerService->delete($record);
    }

    protected function sortableModel(): string
    {
        return Manufacturer::class;
    }

    public function info(int $manufacturer): JsonResponse
    {
        return response()->json(RecordInfo::of($this->manufacturers->findOrFail($manufacturer)));
    }
}
