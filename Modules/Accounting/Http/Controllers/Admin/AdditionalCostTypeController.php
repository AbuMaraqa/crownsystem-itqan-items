<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\AdditionalCostType;
use Modules\Accounting\Http\Requests\Admin\AdditionalCostTypeRequest;
use Modules\Accounting\Repositories\Contracts\AdditionalCostTypeRepositoryInterface;
use Modules\Accounting\Services\AdditionalCostTypeService;
use Modules\Core\Support\UserCellData;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\SafeDelete;

/**
 * بنود التكاليف الاضافية التي تُختار في الطلبية التجميعية. الاضافة والتعديل في نافذة على صفحة القائمة.
 */
class AdditionalCostTypeController extends Controller
{
    public function __construct(
        private readonly AdditionalCostTypeRepositoryInterface $records,
        private readonly AdditionalCostTypeService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/AdditionalCostType/Index', [
            'table' => AdminTable::for($this->records->tableQuery())
                ->search(['name'], translated: null)
                ->filters(AdminFilter::text('name'), AdminFilter::boolean('is_active'), ...AdminFilter::dateRange('created_at'))
                ->sorts('name', 'created_at', 'id')
                ->defaultSort('-created_at')
                ->rows(fn (AdditionalCostType $type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'is_active' => (bool) $type->is_active,
                    'created_by' => UserCellData::of($type->createdBy),
                ]),
        ]);
    }

    public function store(AdditionalCostTypeRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), $request->user()->id);

        return back()->with('success', __('Cost item created successfully'));
    }

    public function update(AdditionalCostTypeRequest $request, int $type): RedirectResponse
    {
        /** @var AdditionalCostType $model */
        $model = $this->records->findOrFail($type);

        $this->service->update($model, $request->validated());

        return back()->with('success', __('Cost item updated successfully'));
    }

    public function active(Request $request, int $type): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        /** @var AdditionalCostType $model */
        $model = $this->records->findOrFail($type);

        $this->service->setActive($model, (bool) $data['is_active']);

        return back()->with('success', __('Cost item status updated successfully'));
    }

    public function destroy(int $type): RedirectResponse
    {
        /** @var AdditionalCostType $model */
        $model = $this->records->findOrFail($type);

        if (! $this->service->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Cost item deleted successfully'));
    }
}
