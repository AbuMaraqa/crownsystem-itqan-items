<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\ReappraisalElement;
use Modules\Accounting\Http\Requests\Admin\ReappraisalElementRequest;
use Modules\Accounting\Repositories\Contracts\ReappraisalElementRepositoryInterface;
use Modules\Accounting\Services\ReappraisalElementService;
use Modules\Core\Http\Controllers\Concerns\ReordersRecords;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;
use Modules\Core\Support\SafeDelete;

/**
 * بنود اعادة التخمين. الحذف ناعم: اعادات التخمين المحفوظة تبقى تعرض اسم البند. الاضافة والتعديل في نافذة على صفحة القائمة.
 */
class ReappraisalElementController extends Controller
{
    use ReordersRecords;

    public function __construct(
        private readonly ReappraisalElementRepositoryInterface $records,
        private readonly ReappraisalElementService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/ReappraisalElement/Index', [
            'table' => AdminTable::for($this->records->tableQuery())
                ->search(['name'], translated: null)
                ->filters(AdminFilter::text('name'), AdminFilter::boolean('is_active'), ...AdminFilter::dateRange('created_at'))
                ->sorts('name', 'created_at', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (ReappraisalElement $element) => [
                    'id' => $element->id,
                    'name' => $element->name,
                    'is_active' => (bool) $element->is_active,
                ]),
        ]);
    }

    public function store(ReappraisalElementRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), $request->user()->id);

        return back()->with('success', __('Reappraisal element created successfully'));
    }

    public function update(ReappraisalElementRequest $request, int $element): RedirectResponse
    {
        /** @var ReappraisalElement $model */
        $model = $this->records->findOrFail($element);

        $this->service->update($model, $request->validated());

        return back()->with('success', __('Reappraisal element updated successfully'));
    }

    public function active(Request $request, int $element): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        /** @var ReappraisalElement $model */
        $model = $this->records->findOrFail($element);

        $this->service->setActive($model, (bool) $data['is_active']);

        return back()->with('success', __('Reappraisal element status updated successfully'));
    }

    public function destroy(int $element): RedirectResponse
    {
        /** @var ReappraisalElement $model */
        $model = $this->records->findOrFail($element);

        if (! $this->service->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Reappraisal element deleted successfully'));
    }

    protected function sortableModel(): string
    {
        return ReappraisalElement::class;
    }

    public function info(int $element): JsonResponse
    {
        return response()->json(RecordInfo::of($this->records->findOrFail($element)));
    }
}
