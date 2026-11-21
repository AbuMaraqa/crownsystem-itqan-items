<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\ClearanceAttachmentType;
use Modules\Accounting\Http\Requests\Admin\ClearanceAttachmentTypeRequest;
use Modules\Accounting\Repositories\Contracts\ClearanceAttachmentTypeRepositoryInterface;
use Modules\Accounting\Services\ClearanceAttachmentTypeService;
use Modules\Core\Http\Controllers\Concerns\ReordersRecords;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;
use Modules\Core\Support\SafeDelete;

/**
 * انواع مرفقات التخليص في الطلبية التجميعية. الاضافة والتعديل في نافذة على صفحة القائمة.
 */
class ClearanceAttachmentTypeController extends Controller
{
    use ReordersRecords;

    public function __construct(
        private readonly ClearanceAttachmentTypeRepositoryInterface $records,
        private readonly ClearanceAttachmentTypeService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/ClearanceAttachmentType/Index', [
            'table' => AdminTable::for($this->records->tableQuery())
                ->search(['name'], translated: null)
                ->filters(AdminFilter::text('name'), ...AdminFilter::dateRange('created_at'))
                ->sorts('name', 'created_at', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (ClearanceAttachmentType $type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                ]),
        ]);
    }

    public function store(ClearanceAttachmentTypeRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), $request->user()->id);

        return back()->with('success', __('Attachment type created successfully'));
    }

    public function update(ClearanceAttachmentTypeRequest $request, int $type): RedirectResponse
    {
        /** @var ClearanceAttachmentType $model */
        $model = $this->records->findOrFail($type);

        $this->service->update($model, $request->validated());

        return back()->with('success', __('Attachment type updated successfully'));
    }

    public function destroy(int $type): RedirectResponse
    {
        /** @var ClearanceAttachmentType $model */
        $model = $this->records->findOrFail($type);

        if (! $this->service->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Attachment type deleted successfully'));
    }

    protected function sortableModel(): string
    {
        return ClearanceAttachmentType::class;
    }

    public function info(int $type): JsonResponse
    {
        return response()->json(RecordInfo::of($this->records->findOrFail($type)));
    }
}
