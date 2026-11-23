<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\SalesOrderType;
use Modules\Accounting\Http\Requests\Admin\SalesOrderTypeRequest;
use Modules\Accounting\Repositories\Contracts\SalesOrderTypeRepositoryInterface;
use Modules\Accounting\Services\SalesOrderTypeService;
use Modules\Core\Http\Controllers\Concerns\DeletesManyRecords;
use Modules\Core\Http\Controllers\Concerns\ReordersRecords;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;
use Modules\Core\Support\SafeDelete;

/**
 * أنواع طلبيات البيع ومهلها القصوى للجاهزية (دليل أنواع الطلبيات). الاضافة والتعديل في نافذة على صفحة القائمة.
 */
class SalesOrderTypeController extends Controller
{
    use DeletesManyRecords;
    use ReordersRecords;

    public function __construct(
        private readonly SalesOrderTypeRepositoryInterface $records,
        private readonly SalesOrderTypeService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/SalesOrderType/Index', [
            'table' => AdminTable::for($this->records->tableQuery())
                ->search(['name', 'description'], translated: null)
                ->filters(AdminFilter::text('name'), AdminFilter::boolean('is_active'), ...AdminFilter::dateRange('created_at'))
                ->sorts('name', 'lead_days', 'created_at', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (SalesOrderType $type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'description' => $type->description,
                    'lead_days' => $type->lead_days,
                    'is_active' => (bool) $type->is_active,
                ]),
        ]);
    }

    public function store(SalesOrderTypeRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), $request->user()->id);

        return back()->with('success', __('Order type saved.'));
    }

    public function update(SalesOrderTypeRequest $request, int $type): RedirectResponse
    {
        /** @var SalesOrderType $model */
        $model = $this->records->findOrFail($type);

        $this->service->update($model, $request->validated());

        return back()->with('success', __('Order type saved.'));
    }

    public function active(Request $request, int $type): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        /** @var SalesOrderType $model */
        $model = $this->records->findOrFail($type);

        $this->service->setActive($model, (bool) $data['is_active']);

        return back()->with('success', __('Order type saved.'));
    }

    public function destroy(int $type): RedirectResponse
    {
        /** @var SalesOrderType $model */
        $model = $this->records->findOrFail($type);

        if (! $this->service->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Order type deleted.'));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->records->tableQuery();
    }

    /**
     * Same rules as destroy(): false (linked records) keeps the row.
     *
     * @param  SalesOrderType  $record
     */
    protected function deleteRecord(Model $record): bool
    {
        return $this->service->delete($record);
    }

    protected function sortableModel(): string
    {
        return SalesOrderType::class;
    }

    public function info(int $type): JsonResponse
    {
        return response()->json(RecordInfo::of($this->records->findOrFail($type)));
    }
}
