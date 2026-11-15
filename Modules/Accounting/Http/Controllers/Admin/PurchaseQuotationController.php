<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Accounting\Enums\PurchaseQuotationStatus;
use Modules\Accounting\Http\Controllers\Admin\Concerns\PurchaseDocumentPage;
use Modules\Accounting\Http\Requests\Admin\PurchaseQuotationRequest;
use Modules\Accounting\Repositories\Contracts\PurchaseQuotationRepositoryInterface;
use Modules\Accounting\Services\AccountingPdfService;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Services\PurchaseQuotationService;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\UserCellData;
use Modules\Items\Support\RecordInfo;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * عروض اسعار الشراء: يُنشأ العرض من طلبية شراء مفتوحة وسطوره سطورها دائماً، ويُدخل سعر المورد
 * لكل سطر (سعر الكغم للصنف الموزون). يُعتمد فتثبت اسعاره، ثم تُنشأ منه فاتورة الشراء. القواعد
 * كلها في PurchaseQuotationService.
 */
class PurchaseQuotationController extends Controller
{
    use PurchaseDocumentPage;

    public function __construct(
        private readonly PurchaseQuotationRepositoryInterface $quotations,
        private readonly PurchaseQuotationService $service,
        private readonly CurrencyExchangeRateService $rates,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/PurchaseQuotation/Index', [
            'table' => AdminTable::for($this->quotations->tableQuery())
                ->search(['reference_no', 'supplier_reference', 'notes'], null)
                ->filters(
                    AdminFilter::text('reference_no'),
                    $this->referenceFilter('purchase_order', 'purchaseOrder'),
                    $this->partyFilter(),
                    AdminFilter::text('supplier_reference'),
                    AdminFilter::exact('status'),
                    AdminFilter::exact('currency', 'currency_id'),
                    AdminFilter::exact('created_by'),
                    AllowedFilter::trashed(),
                    ...AdminFilter::dateRange('quotation_date'),
                    ...AdminFilter::dateRange('valid_until'),
                    ...AdminFilter::numberRange('total_amount'),
                )
                ->sorts('reference_no', 'quotation_date', 'valid_until', 'total_amount', 'status', 'id')
                ->defaultSort('-id')
                ->rows(fn (PurchaseQuotation $quotation) => [
                    'id' => $quotation->id,
                    'reference_no' => $quotation->reference_no,
                    'quotation_date' => $quotation->quotation_date?->format('d/m/Y'),
                    'purchase_order' => $quotation->purchaseOrder?->reference_no,
                    'client' => UserCellData::of($quotation->client),
                    'supplier_reference' => $quotation->supplier_reference,
                    'valid_until' => $quotation->valid_until?->format('d/m/Y'),
                    'total_amount' => number_format((float) $quotation->total_amount, 2).' '.$quotation->currency?->code,
                    'total_amount_full' => Number::money($quotation->total_amount),
                    'currency' => $quotation->currency?->code,
                    'status' => StatusBadge::of($quotation->status),
                    'created_by' => UserCellData::of($quotation->createdBy),
                    'notes' => $quotation->notes,
                    'deleted' => $quotation->trashed(),
                ]),
            'statusOptions' => SelectOptions::from(PurchaseQuotationStatus::options()),
            'currencyOptions' => SelectOptions::currencies(),
            'userOptions' => $this->userOptions(),
        ]);
    }

    /**
     * صفحة الاضافة: تُختار طلبية الشراء (purchase_order في الرابط) فتظهر سطورها بكمياتها. للطلبية
     * عرض فعّال واحد: ان كان لها عرض يُفتح بدل انشاء عرض ثانٍ.
     */
    public function add(Request $request): Response|RedirectResponse
    {
        $currencyId = $this->rates->baseCurrencyId();
        $today = now()->toDateString();
        $order = $request->filled('purchase_order') ? $this->quotations->openOrder($request->integer('purchase_order')) : null;

        if ($order && ($existing = $order->activePurchaseQuotation())) {
            return to_route('accounting.purchase-quotations.edit', $existing->id);
        }

        return Inertia::render('Accounting/PurchaseQuotation/Add', [
            'quotation' => [
                'purchase_order_id' => $order?->id,
                'quotation_date' => $today,
                'valid_until' => null,
                'supplier_reference' => null,
                'currency_id' => $currencyId,
                'exchange_rate' => $this->rate($currencyId, $today),
                'tax_id' => null,
                'quotation_discount' => '0',
                'notes' => null,
                'items' => $order ? $this->service->orderGridLines($order) : [],
            ],
            'order' => $order ? $this->orderSummary($order) : null,
            'referenceNo' => app(ReferenceNumberService::class)->preview('purchase_quotation', now(), ['prefix' => 'PQUO']),
            'statusLabel' => PurchaseQuotationStatus::DRAFT->getLabel(),
            ...$this->documentOptions(),
        ]);
    }

    /**
     * طلبيات الشراء المفتوحة التي ليس لها عرض فعّال (حقل البحث في صفحة الاضافة).
     */
    public function orders(Request $request): JsonResponse
    {
        return response()->json($this->quotations->quotableOrders($request->string('q')->toString()));
    }

    public function store(PurchaseQuotationRequest $request): RedirectResponse
    {
        $data = $request->payload();
        $order = $this->quotations->openOrder((int) $data['purchase_order_id']);

        if (! $order) {
            return back()->with('error', __('A purchase quotation can only be made for an open purchase order.'));
        }

        try {
            $quotation = $this->service->createFromOrder($order, $data);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.purchase-quotations.edit', $quotation->id)->with('success', __('Saved successfully'));
    }

    public function edit(int $purchaseQuotation): Response
    {
        $quotation = $this->quotations->findForPage($purchaseQuotation);
        $invoice = $quotation->purchaseInvoices->first(fn ($invoice) => ! $invoice->isCancelled());

        return Inertia::render('Accounting/PurchaseQuotation/Edit', [
            'quotation' => [
                'purchase_order_id' => $quotation->purchase_order_id,
                'quotation_date' => $quotation->quotation_date?->toDateString(),
                'valid_until' => $quotation->valid_until?->toDateString(),
                'supplier_reference' => $quotation->supplier_reference,
                'currency_id' => $quotation->currency_id,
                'exchange_rate' => Number::clean($quotation->exchange_rate, 8),
                'tax_id' => $quotation->tax_id,
                'quotation_discount' => Number::money($quotation->quotation_discount),
                'notes' => $quotation->notes,
                'items' => $this->service->gridLines($quotation),
            ],
            'record' => [
                'id' => $quotation->id,
                'reference_no' => $quotation->reference_no,
                'status' => StatusBadge::of($quotation->status),
                'editable' => $quotation->canBeEdited(),
                'can_approve' => $quotation->canBeApproved(),
                'can_invoice' => $quotation->canCreateInvoice(),
                'can_cancel' => $quotation->canBeCancelled() && ! $invoice,
                'can_delete' => $quotation->canBeDeleted(),
                'approved_by' => $quotation->approvedBy?->name,
                'approved_at' => $quotation->approved_at?->format('d/m/Y H:i'),
                'cancelled_at' => $quotation->cancelled_at?->format('d/m/Y H:i'),
                'invoice' => $invoice ? ['id' => $invoice->id, 'reference_no' => $invoice->reference_no] : null,
            ],
            'order' => $quotation->purchaseOrder ? $this->orderSummary($quotation->purchaseOrder) : null,
            ...$this->documentOptions(),
        ]);
    }

    public function update(PurchaseQuotationRequest $request, int $purchaseQuotation): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->update($this->find($purchaseQuotation), $request->payload()), __('Saved successfully'));
    }

    /**
     * يحفظ المسودة ثم يعتمدها، كما كان زر «اعتماد».
     */
    public function approve(int $purchaseQuotation): RedirectResponse
    {
        $quotation = $this->find($purchaseQuotation);
        $data = request()->has('quotation_date') && $quotation->canBeEdited() && request()->user()->can('Purchase Quotation Update')
            ? app(PurchaseQuotationRequest::class)->payload()
            : null;

        return $this->attempt(fn () => $this->service->saveAndApprove($quotation, $data), __('Purchase quotation approved. Its prices are now fixed.'));
    }

    public function cancel(int $purchaseQuotation): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->cancel($this->find($purchaseQuotation)), __('Purchase quotation cancelled.'));
    }

    /**
     * فاتورة الشراء من العرض المعتمد: بطلبيته واسعاره.
     */
    public function invoice(int $purchaseQuotation, PurchaseInvoiceService $invoices): RedirectResponse
    {
        try {
            $invoice = $invoices->createFromQuotation($this->find($purchaseQuotation));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.purchase-invoices.edit', $invoice->id)->with('success', __('Purchase invoice created successfully'));
    }

    public function destroy(int $purchaseQuotation): RedirectResponse
    {
        try {
            $this->service->delete($this->find($purchaseQuotation));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.purchase-quotations.index')->with('success', __('Deleted successfully'));
    }

    public function info(int $purchaseQuotation): JsonResponse
    {
        return response()->json(RecordInfo::of($this->find($purchaseQuotation)));
    }

    public function print(int $purchaseQuotation, AccountingPdfService $pdf): mixed
    {
        return $pdf->purchaseQuotation($this->find($purchaseQuotation));
    }

    private function find(int $id): PurchaseQuotation
    {
        /** @var PurchaseQuotation */
        return $this->quotations->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderSummary(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'reference_no' => $order->reference_no,
            'supplier' => $order->client?->name,
            'date' => $order->order_date?->format('d/m/Y'),
            'status' => StatusBadge::of($order->status),
        ];
    }

    private function rate(?int $currencyId, string $date): ?string
    {
        $rate = $this->rates->rateFor($currencyId, $date);

        return $rate ? number_format($rate, 8, '.', '') : null;
    }
}
