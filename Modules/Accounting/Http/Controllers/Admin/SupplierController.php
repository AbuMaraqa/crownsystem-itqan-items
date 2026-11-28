<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Http\Requests\Admin\SupplierRequest;
use Modules\Accounting\Repositories\Contracts\SupplierRepositoryInterface;
use Modules\Accounting\Services\SupplierService;
use Modules\Core\Entities\User;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\RecordInfo;

class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly SupplierService $supplierService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/Supplier/Index', [
            'table' => AdminTable::for($this->suppliers->tableQuery())
                ->search()
                ->filters(
                    AdminFilter::text('name'),
                    AdminFilter::text('email'),
                    AdminFilter::text('phone'),
                    AdminFilter::text('tax_number'),
                    ...AdminFilter::dateRange('created_at'),
                )
                ->sorts('name', 'email', 'created_at', 'id')
                ->withCreator()
                ->rows(fn (User $supplier) => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'avatar' => $supplier->avatar_url,
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                    'address' => $supplier->address,
                    'tax_number' => $supplier->tax_number,
                ]),
        ]);
    }

    public function add(): Response
    {
        return Inertia::render('Accounting/Supplier/Add');
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $this->supplierService->create($request->validated());

        return to_route('accounting.suppliers.index')->with('success', __('Supplier created successfully'));
    }

    public function edit(int $supplier): Response
    {
        $model = $this->suppliers->findSupplier($supplier);

        return Inertia::render('Accounting/Supplier/Edit', [
            'supplier' => [
                'id' => $model->id,
                'name' => $model->name,
                'email' => $model->email,
                'phone' => $model->phone,
                'tax_number' => $model->tax_number,
                'address' => $model->address,
            ],
        ]);
    }

    public function update(SupplierRequest $request, int $supplier): RedirectResponse
    {
        $this->supplierService->update($this->suppliers->findSupplier($supplier), $request->validated());

        return to_route('accounting.suppliers.index')->with('success', __('Supplier updated successfully'));
    }

    public function info(int $supplier): JsonResponse
    {
        return response()->json(RecordInfo::of($this->suppliers->findSupplier($supplier)));
    }
}
