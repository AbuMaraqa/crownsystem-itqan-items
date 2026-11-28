<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Accounting\Enums\SalesReturnStatus;
use Modules\Accounting\Http\Controllers\Admin\Concerns\DeletesManyDocuments;
use Modules\Accounting\Http\Controllers\Admin\Concerns\SalesDocumentPage;
use Modules\Accounting\Http\Requests\Admin\SalesReturnRequest;
use Modules\Accounting\Repositories\Contracts\SalesReturnRepositoryInterface;
use Modules\Accounting\Services\SalesReturnService;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\PaymentAllocationPanel;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\UserCellData;
use Modules\Core\Support\RecordInfo;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * مرتجعات البيع من فاتورة مرحّلة: المرتجع الجديد يأخذ كل القابل للارجاع، ثم يُدخل ما يُرجع فعلاً
 * (الكمية او عدد قطع كل صنف فرعي، بحد ما فوتر — ReturnPieces)، ثم ترحيل (عكس الايراد، وعودة
 * البضاعة بكلفة خروجها، وتسديد الفاتورة) او الغاء. القواعد كلها في SalesReturnService.
 */
class SalesReturnController extends Controller
{
    use DeletesManyDocuments;
    use SalesDocumentPage;

    public function __construct(
        private readonly SalesReturnRepositoryInterface $returns,
        private readonly SalesReturnService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/SalesReturn/Index', [
            'table' => AdminTable::for($this->returns->tableQuery())
                ->search()
                ->filters(
                    AdminFilter::text('reference_no'),
                    $this->referenceFilter('sales_invoice', 'salesInvoice'),
                    $this->partyFilter(),
                    AdminFilter::exact('warehouse', 'warehouse_id'),
                    AdminFilter::text('notes'),
                    AdminFilter::exact('status'),
                    AdminFilter::exact('currency', 'currency_id'),
                    AllowedFilter::trashed(),
                    ...AdminFilter::dateRange('return_date'),
                    ...AdminFilter::numberRange('total_amount'),
                )
                ->sorts('reference_no', 'return_date', 'total_amount', 'status', 'id')
                ->defaultSort('-return_date')
                ->withCreator()
                ->rows(fn (SalesReturn $return) => [
                    'id' => $return->id,
                    'reference_no' => $return->reference_no,
                    'return_date' => $return->return_date?->format('d/m/Y'),
                    'sales_invoice' => $return->salesInvoice?->reference_no,
                    'client' => UserCellData::of($return->client),
                    'warehouse' => $return->warehouse?->name,
                    'currency' => $return->currency?->code,
                    'exchange_rate' => Number::clean($return->exchange_rate, 8),
                    'tax' => $return->tax?->name,
                    'tax_rate' => number_format((float) $return->tax_rate, 2).'%',
                    'total_amount' => number_format((float) $return->total_amount, 2),
                    'total_amount_full' => Number::money($return->total_amount),
                    'status' => StatusBadge::of($return->status),
                    'notes' => $return->notes,
                    'deleted' => $return->trashed(),
                    'editable' => ! $return->trashed() && $return->canBeEdited(),
                ]),
            'statusOptions' => SelectOptions::from(SalesReturnStatus::options()),
            'currencyOptions' => SelectOptions::currencies(),
            'warehouseOptions' => $this->documentOptions()['warehouseOptions'],
        ]);
    }

    public function add(): Response
    {
        return Inertia::render('Accounting/SalesReturn/Add', [
            'salesReturn' => [
                'client_id' => null,
                'sales_invoice_id' => null,
                'warehouse_id' => null,
                'return_date' => now()->toDateString(),
                'notes' => null,
            ],
            'referenceNo' => app(ReferenceNumberService::class)->preview('sales_return', now(), ['prefix' => 'SRET']),
            'statusLabel' => SalesReturnStatus::DRAFT->getLabel(),
            'warehouseOptions' => $this->documentOptions()['warehouseOptions'],
        ]);
    }

    /**
     * المرتجع الجديد يأخذ كل ما بقي قابلاً للارجاع من الفاتورة، ويُعدَّل في صفحته.
     */
    public function store(SalesReturnRequest $request): RedirectResponse
    {
        try {
            $return = $this->service->create($request->formData());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.sales-returns.edit', $return->id)->with('success', __('Sales return created successfully'));
    }

    /**
     * فواتير الزبون المرحّلة التي يُرجع منها (RemoteSelect).
     */
    public function invoices(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->service->invoiceOptions($data['client_id'] ?? null, $data['q'] ?? null));
    }

    /**
     * ما يعرضه النموذج من الفاتورة المختارة: المخزن والعملة والضريبة وسعر الصرف.
     */
    public function invoiceDefaults(Request $request): JsonResponse
    {
        $data = $request->validate(['sales_invoice_id' => ['required', 'integer']]);
        $invoice = SalesInvoice::query()->with(['currency', 'tax', 'salesOrder'])->findOrFail($data['sales_invoice_id']);

        return response()->json([
            ...$this->service->invoiceDefaults($invoice),
            'currency' => $invoice->currency?->code,
            'tax' => $invoice->tax ? $invoice->tax->name.' - '.number_format((float) $invoice->tax_rate, 2).'%' : null,
            'total_amount' => Number::money($invoice->total_amount),
        ]);
    }

    /**
     * صفحة المرتجع: ما يُرجع من كل سطر من سطور الفاتورة ما دام مسودة، ثم عرض للقراءة مع الغائه
     * ولوحة تخصيص رصيده على فواتير الزبون.
     */
    public function edit(int $salesReturn): Response
    {
        $return = $this->returns->findForPage($salesReturn);
        $user = auth()->user();

        return Inertia::render('Accounting/SalesReturn/Edit', [
            'salesReturn' => [
                'warehouse_id' => $return->warehouse_id,
                'return_date' => $return->return_date?->toDateString(),
                'notes' => $return->notes,
                'items' => $return->salesInvoice ? $this->service->gridRows($return) : [],
            ],
            'record' => [
                'id' => $return->id,
                'reference_no' => $return->reference_no,
                'status' => StatusBadge::of($return->status),
                'client' => $return->client?->name,
                'sales_invoice' => $return->salesInvoice ? ['id' => $return->salesInvoice->id, 'reference_no' => $return->salesInvoice->reference_no] : null,
                'currency' => $return->currency?->code,
                'exchange_rate' => Number::clean($return->exchange_rate, 8),
                'tax_rate' => Number::clean($return->tax_rate),
                // حصة المرتجع من خصم الفاتورة العام، تُحسب عند الحفظ.
                'invoice_discount' => Number::money($return->invoice_discount),
                'editable' => $return->canBeEdited() && (bool) $user?->can('Sales Return Update'),
                'can_post' => $return->canBePosted(),
                'can_cancel' => $return->isPosted(),
                'can_delete' => $return->canBeEdited(),
                'cancelled' => $return->isCancelled(),
                'cancelled_at' => $return->cancelled_at?->format('d/m/Y H:i'),
                'cancellation_reason' => $return->cancellation_reason,
            ],
            'allocationPanel' => $return->isPosted() || $return->isCancelled() ? PaymentAllocationPanel::of($return) : null,
            'warehouseOptions' => $this->documentOptions()['warehouseOptions'],
        ]);
    }

    public function update(SalesReturnRequest $request, int $salesReturn): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->update($this->find($salesReturn), $request->formData()), __('Saved successfully'));
    }

    /**
     * يحفظ ما أُدخل (لصاحب صلاحية التعديل) ثم يرحّل المرتجع.
     */
    public function post(int $salesReturn): RedirectResponse
    {
        $data = request()->has('return_date') && auth()->user()?->can('Sales Return Update')
            ? app(SalesReturnRequest::class)->formData()
            : null;

        return $this->attempt(
            fn () => $this->service->saveThen($this->find($salesReturn), $data, fn (SalesReturn $return) => $this->service->post($return)),
            __('Posted successfully'),
        );
    }

    public function cancel(Request $request, int $salesReturn): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->cancel($this->find($salesReturn), $data['reason'] ?? null), __('Cancelled successfully'));
    }

    public function destroy(int $salesReturn): RedirectResponse
    {
        try {
            $this->service->delete($this->find($salesReturn));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.sales-returns.index')->with('success', __('Sales return deleted successfully'));
    }

    /**
     * The list's rows, deleted ones included (its «deleted» filter shows them; they are refused).
     */
    protected function bulkDeleteQuery(): Builder
    {
        return $this->returns->tableQuery()->withTrashed();
    }

    /**
     * @param  SalesReturn  $document
     */
    protected function deleteDocument(Model $document): void
    {
        $this->service->delete($document);
    }

    public function info(int $salesReturn): JsonResponse
    {
        return response()->json(RecordInfo::of($this->returns->query()->withTrashed()->findOrFail($salesReturn)));
    }

    private function find(int $id): SalesReturn
    {
        /** @var SalesReturn */
        return $this->returns->findOrFail($id);
    }
}
