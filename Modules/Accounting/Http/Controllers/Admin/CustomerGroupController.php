<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\CustomerGroup;
use Modules\Accounting\Http\Requests\Admin\CustomerGroupRequest;
use Modules\Accounting\Repositories\Contracts\CustomerGroupRepositoryInterface;
use Modules\Accounting\Services\CustomerGroupService;
use Modules\Accounting\Support\SelectOptions;
use Modules\Core\Support\UserCellData;
use Modules\Core\Entities\User;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\SafeDelete;
use Modules\Items\Entities\PriceList;

/**
 * تصنيفات العملاء: الاضافة والتعديل في نافذة على صفحة القائمة، والتفعيل من الجدول.
 */
class CustomerGroupController extends Controller
{
    public function __construct(
        private readonly CustomerGroupRepositoryInterface $groups,
        private readonly CustomerGroupService $groupService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/CustomerGroup/Index', [
            'table' => AdminTable::for($this->groups->tableQuery())
                ->search(['name'], translated: null)
                ->filters(
                    AdminFilter::boolean('is_active'),
                    AdminFilter::exact('price_list_id'),
                    AdminFilter::boolean('use_last_price'),
                    ...AdminFilter::numberRange('profit_margin'),
                    ...AdminFilter::dateRange('created_at'),
                )
                ->sorts('name', 'profit_margin', 'customers_count', 'created_at', 'id')
                ->defaultSort('-created_at')
                ->rows(fn (CustomerGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'profit_margin' => CustomerGroup::formatMargin($group->profit_margin),
                    'customers_count' => (int) $group->customers_count,
                    'price_list_id' => $group->price_list_id ? (int) $group->price_list_id : null,
                    'use_last_price' => (bool) $group->use_last_price,
                    'notes' => $group->notes,
                    'is_active' => (bool) $group->is_active,
                    'created_by' => UserCellData::of($group->createdBy),
                    // The saved members with their names, so the customers field shows them.
                    'customers' => $group->customers
                        ->map(fn (User $customer) => ['value' => (int) $customer->id, 'label' => (string) $customer->name])
                        ->values()
                        ->all(),
                ]),
            'priceLists' => SelectOptions::from(PriceList::options()),
        ]);
    }

    public function store(CustomerGroupRequest $request): RedirectResponse
    {
        $this->groupService->create($request->validated(), $request->user()->id);

        return back()->with('success', __('Customer group created successfully'));
    }

    public function update(CustomerGroupRequest $request, int $group): RedirectResponse
    {
        /** @var CustomerGroup $model */
        $model = $this->groups->findOrFail($group);

        $this->groupService->update($model, $request->validated());

        return back()->with('success', __('Customer group updated successfully'));
    }

    public function active(Request $request, int $group): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        /** @var CustomerGroup $model */
        $model = $this->groups->findOrFail($group);

        $this->groupService->setActive($model, (bool) $data['is_active']);

        return back()->with('success', __('Customer group status updated successfully'));
    }

    public function destroy(int $group): RedirectResponse
    {
        /** @var CustomerGroup $model */
        $model = $this->groups->findOrFail($group);

        if (! $this->groupService->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Customer group deleted successfully'));
    }

    /**
     * The customers field searches here: `?q=term` → `[{value, label}]`.
     */
    public function customers(Request $request): JsonResponse
    {
        return response()->json($this->groups->customerOptions((string) $request->query('q', '')));
    }
}
