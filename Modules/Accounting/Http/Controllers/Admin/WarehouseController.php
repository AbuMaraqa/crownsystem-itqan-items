<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Locales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Http\Requests\Admin\WarehouseRequest;
use Modules\Accounting\Repositories\Contracts\WarehouseRepositoryInterface;
use Modules\Accounting\Services\WarehouseService;
use Modules\Accounting\Support\SelectOptions;
use Modules\Branch\Enums\BranchStatus;
use Modules\Branch\Http\Requests\Admin\BranchRequest;
use Modules\Branch\Services\BranchService;
use Modules\Core\Http\Controllers\Concerns\ReordersRecords;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\SafeDelete;
use Modules\GeoLocation\Repositories\Contracts\CityRepositoryInterface;
use Modules\GeoLocation\Repositories\Contracts\CountryRepositoryInterface;
use Modules\GeoLocation\Support\GeoFilters;
use Modules\Core\Support\RecordInfo;
use Modules\Items\Support\TranslatedSort;
use Spatie\QueryBuilder\AllowedSort;

class WarehouseController extends Controller
{
    use ReordersRecords;

    public function __construct(
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly WarehouseService $warehouseService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/Warehouse/Index', [
            'table' => AdminTable::for($this->warehouses->tableQuery())
                ->search(['code', 'phone', 'email'])
                ->filters(
                    AdminFilter::translated('name'),
                    AdminFilter::text('code'),
                    GeoFilters::integer('branch', 'branch_id'),
                    AdminFilter::boolean('is_active'),
                    GeoFilters::locale(),
                    ...AdminFilter::dateRange('created_at'),
                )
                ->sorts(AllowedSort::custom('name', new TranslatedSort), 'code', 'is_active', 'created_at', 'id')
                ->reorderable()
                ->withCreator()
                ->rows(fn (Warehouse $warehouse) => [
                    'id' => $warehouse->id,
                    'name' => (string) $warehouse->name,
                    'code' => $warehouse->code,
                    'branch' => $warehouse->branch?->name,
                    'inventory_account' => $warehouse->inventoryAccount ? [
                        'code' => $warehouse->inventoryAccount->code,
                        'name' => (string) $warehouse->inventoryAccount->name,
                    ] : null,
                    'phone' => $warehouse->phone,
                    'email' => $warehouse->email,
                    'address' => $warehouse->address,
                    'description' => $warehouse->description,
                    'is_active' => (bool) $warehouse->is_active,
                    'locales' => $warehouse->translations->pluck('locale'),
                    'stock_url' => route('accounting.inventory.stock.index', ['tableFilters' => ['warehouse' => ['value' => $warehouse->id]]]),
                ]),
            'branchOptions' => SelectOptions::branches(),
            'locales' => Locales::keys(),
        ]);
    }

    public function add(): Response
    {
        return Inertia::render('Accounting/Warehouse/Add', $this->formOptions());
    }

    public function store(WarehouseRequest $request): RedirectResponse
    {
        $this->warehouseService->create($request->validated(), app()->getLocale(), $request->user()->id);

        return to_route('accounting.warehouses.index')->with('success', __('Warehouse created successfully'));
    }

    public function edit(int $warehouse): Response
    {
        $model = $this->warehouses->findForForm($warehouse);
        $translation = $model->translate(app()->getLocale(), false);

        return Inertia::render('Accounting/Warehouse/Edit', [
            'warehouse' => [
                'id' => $model->id,
                'name' => $translation?->name ?? '',
                'code' => $model->code,
                'branch_id' => $model->branch_id,
                'phone' => $model->phone,
                'email' => $model->email,
                'latitude' => $model->latitude,
                'longitude' => $model->longitude,
                'is_active' => (bool) $model->is_active,
                'address' => $translation?->address ?? '',
                'description' => $translation?->description ?? '',
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(WarehouseRequest $request, int $warehouse): RedirectResponse
    {
        /** @var Warehouse $model */
        $model = $this->warehouses->findOrFail($warehouse);

        $this->warehouseService->update($model, $request->validated(), app()->getLocale());

        return to_route('accounting.warehouses.index')->with('success', __('Warehouse updated successfully'));
    }

    public function destroy(int $warehouse): RedirectResponse
    {
        /** @var Warehouse $model */
        $model = $this->warehouses->findOrFail($warehouse);

        if (! $this->warehouseService->delete($model)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Warehouse deleted successfully'));
    }

    public function destroyMany(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];

        if (! $this->warehouseService->deleteMany($ids)) {
            return back()->with('error', SafeDelete::message());
        }

        return back()->with('success', __('Warehouses deleted successfully'));
    }

    public function info(int $warehouse): JsonResponse
    {
        return response()->json(RecordInfo::of($this->warehouses->findOrFail($warehouse)));
    }

    protected function sortableModel(): string
    {
        return Warehouse::class;
    }

    /**
     * اضافة فرع من نافذة حقل الفرع دون مغادرة النموذج. الصفحة تعود بقائمة الفروع الجديدة
     * فيختار النموذج الفرع المضاف.
     */
    public function storeBranch(BranchRequest $request, BranchService $branches): RedirectResponse
    {
        $branches->create($request->validated(), app()->getLocale(), $request->user()->id);

        return back()->with('success', __('Branch created successfully'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'branchOptions' => SelectOptions::branches(),
            // نافذة «فرع جديد» تحتاج الدول والمدن وحالات الفرع.
            'branchForm' => fn () => ! request()->user()?->can('Branch Create') ? null : [
                'countries' => app(CountryRepositoryInterface::class)->options(),
                'cities' => app(CityRepositoryInterface::class)->options(),
                'statuses' => array_map(
                    fn (BranchStatus $status) => ['value' => $status->value, 'label' => $status->getLabel()],
                    BranchStatus::cases(),
                ),
            ],
        ];
    }
}
