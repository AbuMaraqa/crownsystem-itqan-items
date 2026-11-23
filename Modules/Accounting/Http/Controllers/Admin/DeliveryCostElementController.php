<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\DeliveryCostElement;
use Modules\Accounting\Http\Requests\Admin\DeliveryCostElementRequest;
use Modules\Accounting\Repositories\Contracts\DeliveryCostElementRepositoryInterface;
use Modules\Accounting\Services\DeliveryCostElementService;
use Modules\Core\Http\Controllers\Concerns\DeletesManyRecords;
use Modules\Core\Http\Controllers\Concerns\ReordersRecords;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;
use Modules\Core\Support\SafeDelete;

/**
 * عناصر تقدير تكلفة التوصيل في الطلبية التجميعية. الاضافة والتعديل في نافذة على صفحة القائمة.
 */
class DeliveryCostElementController extends Controller
{
    use DeletesManyRecords;
    use ReordersRecords;

    public function __construct(
        private readonly DeliveryCostElementRepositoryInterface $records,
        private readonly DeliveryCostElementService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/DeliveryCostElement/Index', [
            'table' => AdminTable::for($this->records->tableQuery())
                ->search(['name'], translated: null)
                ->filters(AdminFilter::text('name'), ...AdminFilter::dateRange('created_at'))
                ->sorts('name', 'created_at', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (DeliveryCostElement $element) => [
                    'id' => $element->id,
                    'name' => $element->name,
                ]),
        ]);
    }

    public function store(DeliveryCostElementRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), $request->user()->id);

        return back()->with('success', __('Cost element created successfully'));
    }

    public function update(DeliveryCostElementRequest $request, int $element): RedirectResponse
    {
        /** @var DeliveryCostElement $model */
        $model = $this->records->findOrFail($element);

        $this->service->update($model, $request->validated());

        return back()->with('success', __('Cost element updated successfully'));
    }

    public function destroy(int $element): RedirectResponse
    {
        /** @var DeliveryCostElement $model */
        $model = $this->records->findOrFail($element);

        if (! $this->service->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Cost element deleted successfully'));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->records->tableQuery();
    }

    /**
     * Same rules as destroy(): false (linked records) keeps the row.
     *
     * @param  DeliveryCostElement  $record
     */
    protected function deleteRecord(Model $record): bool
    {
        return $this->service->delete($record);
    }

    protected function sortableModel(): string
    {
        return DeliveryCostElement::class;
    }

    public function info(int $element): JsonResponse
    {
        return response()->json(RecordInfo::of($this->records->findOrFail($element)));
    }
}
