<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\AttachmentCategory;
use Modules\Accounting\Http\Requests\Admin\AttachmentCategoryRequest;
use Modules\Accounting\Repositories\Contracts\AttachmentCategoryRepositoryInterface;
use Modules\Accounting\Services\AttachmentCategoryService;
use Modules\Core\Support\UserCellData;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\SafeDelete;

/**
 * تصنيفات المرفقات: تظهر خيارات عند ارفاق ملف في الطلبية التجميعية. الاضافة والتعديل في نافذة على صفحة القائمة.
 */
class AttachmentCategoryController extends Controller
{
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
                ->defaultSort('-created_at')
                ->rows(fn (AttachmentCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'created_by' => UserCellData::of($category->createdBy),
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
}
