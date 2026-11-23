<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\AttachmentCategory;
use Modules\Accounting\Http\Requests\Admin\AttachmentCategoryRequest;
use Modules\Accounting\Repositories\Contracts\AttachmentCategoryRepositoryInterface;
use Modules\Accounting\Services\AttachmentCategoryService;
use Modules\Core\Http\Controllers\Concerns\DeletesManyRecords;
use Modules\Core\Http\Controllers\Concerns\ReordersRecords;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;
use Modules\Core\Support\SafeDelete;

/**
 * تصنيفات المرفقات: تظهر خيارات عند ارفاق ملف في الطلبية التجميعية. الاضافة والتعديل في نافذة على صفحة القائمة.
 */
class AttachmentCategoryController extends Controller
{
    use DeletesManyRecords;
    use ReordersRecords;

    public function __construct(
        private readonly AttachmentCategoryRepositoryInterface $records,
        private readonly AttachmentCategoryService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/AttachmentCategory/Index', [
            'table' => AdminTable::for($this->records->tableQuery())
                ->search(['name'], translated: null)
                ->filters(AdminFilter::text('name'), ...AdminFilter::dateRange('created_at'))
                ->sorts('name', 'created_at', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (AttachmentCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                ]),
        ]);
    }

    public function store(AttachmentCategoryRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), $request->user()->id);

        return back()->with('success', __('Attachment category created successfully'));
    }

    public function update(AttachmentCategoryRequest $request, int $category): RedirectResponse
    {
        /** @var AttachmentCategory $model */
        $model = $this->records->findOrFail($category);

        $this->service->update($model, $request->validated());

        return back()->with('success', __('Attachment category updated successfully'));
    }

    public function destroy(int $category): RedirectResponse
    {
        /** @var AttachmentCategory $model */
        $model = $this->records->findOrFail($category);

        if (! $this->service->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Attachment category deleted successfully'));
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->records->tableQuery();
    }

    /**
     * Same rules as destroy(): false (linked records) keeps the row.
     *
     * @param  AttachmentCategory  $record
     */
    protected function deleteRecord(Model $record): bool
    {
        return $this->service->delete($record);
    }

    protected function sortableModel(): string
    {
        return AttachmentCategory::class;
    }

    public function info(int $category): JsonResponse
    {
        return response()->json(RecordInfo::of($this->records->findOrFail($category)));
    }
}
