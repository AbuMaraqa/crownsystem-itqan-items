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
use Modules\Items\Entities\Unit;
use Modules\Items\Http\Requests\Admin\UnitRequest;
use Modules\Items\Repositories\Contracts\UnitRepositoryInterface;
use Modules\Items\Services\UnitService;
use Modules\Core\Support\RecordInfo;
use Modules\Items\Support\TranslatedSort;
use Spatie\QueryBuilder\AllowedSort;

class UnitController extends Controller
{
    use DeletesManyRecords;
    use ReordersRecords;

    public function __construct(
        private readonly UnitRepositoryInterface $units,
        private readonly UnitService $unitService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Items/Unit/Index', [
            'table' => AdminTable::for($this->units->tableQuery())
                ->search(['slug'])
                ->filters(AdminFilter::translated('name'), ...AdminFilter::dateRange('created_at'))
                ->sorts(AllowedSort::custom('name', new TranslatedSort), 'slug', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (Unit $unit) => [
                    'id' => $unit->id,
                    'name' => (string) $unit->name,
                    'slug' => $unit->slug,
                    'description' => $unit->description,
                    'locales' => $unit->translations->pluck('locale'),
                    // The modal edits the current locale only, without the fallback the table shows.
                    'form' => [
                        'name' => $unit->translate(app()->getLocale(), false)?->name ?? '',
                        'description' => $unit->translate(app()->getLocale(), false)?->description ?? '',
                    ],
                ]),
        ]);
    }

    public function store(UnitRequest $request): RedirectResponse
    {
        $this->unitService->create($request->validated(), app()->getLocale(), $request->user()->id);

        return back()->with('success', __('Unit created successfully'));
    }

    public function update(UnitRequest $request, int $unit): RedirectResponse
    {
        /** @var Unit $model */
        $model = $this->units->findOrFail($unit);

        $this->unitService->update($model, $request->validated(), app()->getLocale());

        return back()->with('success', __('Unit updated successfully'));
    }

    public function destroy(int $unit): RedirectResponse
    {
        /** @var Unit $model */
        $model = $this->units->findOrFail($unit);

        try {
            if (! $this->unitService->delete($model)) {
                return back()->with('error', SafeDelete::message());
            }
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Unit deleted successfully'));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->units->tableQuery();
    }

    /**
     * @param  Unit  $record
     */
    protected function deleteRecord(Model $record): bool
    {
        return $this->unitService->delete($record);
    }

    protected function sortableModel(): string
    {
        return Unit::class;
    }

    public function info(int $unit): JsonResponse
    {
        return response()->json(RecordInfo::of($this->units->findOrFail($unit)));
    }
}
