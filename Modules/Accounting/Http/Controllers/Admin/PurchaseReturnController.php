<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\PurchaseReturn;
use Modules\Accounting\Enums\PurchaseReturnStatus;
use Modules\Accounting\Http\Controllers\Admin\Concerns\PurchaseDocumentPage;
use Modules\Accounting\Http\Requests\Admin\PurchaseReturnRequest;
use Modules\Accounting\Repositories\Contracts\PurchaseReturnRepositoryInterface;
use Modules\Accounting\Services\AccountingPdfService;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Services\PurchaseReturnService;
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
 * مرتجعات المشتريات: تُنشأ من فاتورة شراء مرحّلة بكل ما بقي قابلاً للارجاع، ثم يُعدَّل في صفحتها
 * ما يُرجع (كمية صنف الكمية، وعدد قطع كل صنف فرعي). الترحيل يُخرج البضاعة من المخزن بكلفة FIFO
 * ويقيد ويخصص المرتجع على فاتورته؛ الالغاء يعكس ذلك. القواعد كلها في PurchaseReturnService.
 */
class PurchaseReturnController extends Controller
{
    use PurchaseDocumentPage;

    public function __construct(
        private readonly PurchaseReturnRepositoryInterface $returns,
        private readonly PurchaseReturnService $service,
        private readonly CurrencyExchangeRateService $rates,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/PurchaseReturn/Index', [
            'table' => AdminTable::for($this->returns->tableQuery())
                ->search(['reference_no', 'notes'], null)
                ->filters(
                    AdminFilter::text('reference_no'),
                    $this->referenceFilter('purchase_invoice', 'purchaseInvoice'),
                    $this->partyFilter(),
                    AdminFilter::exact('warehouse', 'warehouse_id'),
                    AdminFilter::text('notes'),
                    AdminFilter::exact('status'),
                    AdminFilter::exact('currency', 'currency_id'),
                    AdminFilter::exact('created_by'),
                    AllowedFilter::trashed(),
                    ...AdminFilter::dateRange('return_date'),
                    ...AdminFilter::numberRange('total_amount'),
                )
                ->sorts('reference_no', 'return_date', 'total_amount', 'status', 'id')
                ->defaultSort('-return_date')
                ->withCreator()
                ->rows(fn (PurchaseReturn $return) => [
                    'id' => $return->id,
                    'reference_no' => $return->reference_no,
                    'return_date' => $return->return_date?->format('d/m/Y'),
                    'purchase_invoice' => $return->purchaseInvoice?->reference_no,
                    'client' => UserCellData::of($return->client),
                    'warehouse' => $return->warehouse?->name,
                    'currency' => $return->currency?->code,
                    'exchange_rate' => number_format((float) $return->exchange_rate, 8),
                    'tax' => $return->tax?->name,
                    'tax_rate' => number_format((float) $return->tax_rate, 2).'%',
                    'total_amount' => number_format((float) $return->total_amount, 2),
                    'total_amount_full' => Number::money($return->total_amount),
                    'status' => StatusBadge::of($return->status),
                    'notes' => $return->notes,
                    'deleted' => $return->trashed(),
                    'editable' => ! $return->trashed() && $return->canBeEdited(),
                ]),
            'statusOptions' => SelectOptions::from(PurchaseReturnStatus::options()),
            'currencyOptions' => SelectOptions::currencies(),
            'warehouseOptions' => $this->documentOptions()['warehouseOptions'],
            'userOptions' => $this->userOptions(),
        ]);
    }

    public function add(): Response
    {
        $currencyId = $this->rates->baseCurrencyId();
        $today = now()->toDateString();
        $rate = $this->rates->rateFor($currencyId, $today);

        return Inertia::render('Accounting/PurchaseReturn/Add', [
            'purchaseReturn' => [
                'client_id' => null,
                'purchase_invoice_id' => null,
                'warehouse_id' => null,
                'return_date' => $today,
                'notes' => null,
            ],
            'defaults' => [
                'currency_id' => $currencyId,
                'tax_id' => null,
                'exchange_rate' => $rate ? number_format($rate, 8, '.', '') : '1',
            ],
            'referenceNo' => app(ReferenceNumberService::class)->preview('purchase_return', now(), ['prefix' => 'PRET']),
            'statusLabel' => PurchaseReturnStatus::DRAFT->getLabel(),
            ...$this->documentOptions(),
        ]);
    }

    /**
     * فواتير المورد المرحّلة (حقل «فاتورة الشراء» في صفحة الاضافة).
     */
    public function invoices(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:255']]);

        return response()->json(filled($data['client_id'] ?? null) ? $this->returns->returnableInvoices((int) $data['client_id'], (string) ($data['q'] ?? '')) : []);
    }

    /**
     * ما تُعبأ به صفحة الاضافة عند اختيار الفاتورة.
     */
    public function invoiceDraft(Request $request): JsonResponse
    {
        $data = $request->validate(['purchase_invoice_id' => ['required', 'integer'], 'client_id' => ['nullable', 'integer'], 'return_date' => ['nullable', 'date']]);
        $invoice = $this->returns->returnableInvoice((int) $data['purchase_invoice_id'], isset($data['client_id']) ? (int) $data['client_id'] : null);

        if (! $invoice) {
            return response()->json(['message' => __('Select a posted purchase invoice to return from.')], 422);
        }

        return response()->json($this->service->draftFromInvoice($invoice, $data['return_date'] ?? null));
    }

    public function store(PurchaseReturnRequest $request): RedirectResponse
    {
        try {
            $return = $this->service->create($request->payload());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.purchase-returns.edit', $return->id)->with('success', __('Purchase return created successfully'));
    }

    public function edit(Request $request, int $purchaseReturn): Response
    {
        $return = $this->returns->findForPage($purchaseReturn);
        $invoice = $return->purchaseInvoice;

        return Inertia::render('Accounting/PurchaseReturn/Edit', [
            'purchaseReturn' => [
                'warehouse_id' => $return->warehouse_id,
                'return_date' => $return->return_date?->toDateString(),
                'notes' => $return->notes,
                'items' => $invoice ? $this->service->gridRows($return) : [],
            ],
            'record' => [
                'id' => $return->id,
                'reference_no' => $return->reference_no,
                'status' => StatusBadge::of($return->status),
                'party' => $return->client?->name,
                'currency' => $return->currency?->code,
                'exchange_rate' => Number::clean($return->exchange_rate, 8),
                'tax_rate' => Number::clean($return->tax_rate),
                'editable' => $return->canBeEdited() && $request->user()->can('Purchase Return Update'),
                'can_post' => $return->canBePosted(),
                'can_cancel' => $return->isPosted(),
                'can_delete' => $return->canBeEdited(),
                'cancelled' => $return->isCancelled(),
                'cancelled_at' => $return->cancelled_at?->format('d/m/Y H:i'),
                'cancellation_reason' => $return->cancellation_reason,
                'invoice' => $invoice ? ['id' => $invoice->id, 'reference_no' => $invoice->reference_no] : null,
                'journal_entry' => $return->journalEntry ? ['id' => $return->journalEntry->id, 'entry_number' => $return->journalEntry->entry_number] : null,
                // حصة المرتجع من خصم الفاتورة العام، تُحسب عند الحفظ.
                'totals' => [
                    'sub_total' => Number::money($return->sub_total),
                    'items_discount' => Number::money($return->items_discount),
                    'invoice_discount' => Number::money($return->invoice_discount),
                    'tax_amount' => Number::money($return->tax_amount),
                    'total_amount' => Number::money($return->total_amount),
                ],
            ],
            'allocationPanel' => $return->isPosted() || $return->isCancelled() ? PaymentAllocationPanel::of($return) : null,
            'warehouseOptions' => $this->documentOptions()['warehouseOptions'],
        ]);
    }

    public function update(PurchaseReturnRequest $request, int $purchaseReturn): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->update($this->find($purchaseReturn), $request->payload()), __('Saved successfully'));
    }

    /**
     * يحفظ المسودة بما أُدخل ثم يرحّلها.
     */
    public function post(int $purchaseReturn): RedirectResponse
    {
        $return = $this->find($purchaseReturn);
        $data = request()->has('return_date') && $return->canBeEdited() && request()->user()->can('Purchase Return Update')
            ? app(PurchaseReturnRequest::class)->payload()
            : null;

        return $this->attempt(fn () => $this->service->saveAndPost($return, $data), __('Posted successfully'));
    }

    public function cancel(Request $request, int $purchaseReturn): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->cancel($this->find($purchaseReturn), $data['reason'] ?? null), __('Cancelled successfully'));
    }

    public function destroy(int $purchaseReturn): RedirectResponse
    {
        try {
            $this->service->delete($this->find($purchaseReturn));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.purchase-returns.index')->with('success', __('Purchase return deleted successfully'));
    }

    public function info(int $purchaseReturn): JsonResponse
    {
        return response()->json(RecordInfo::of($this->returns->query()->withTrashed()->findOrFail($purchaseReturn)));
    }

    public function print(int $purchaseReturn, AccountingPdfService $pdf): mixed
    {
        return $pdf->purchaseReturn($this->find($purchaseReturn));
    }

    private function find(int $id): PurchaseReturn
    {
        /** @var PurchaseReturn */
        return $this->returns->findOrFail($id);
    }
}
