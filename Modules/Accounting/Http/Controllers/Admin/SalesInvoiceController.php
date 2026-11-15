<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Accounting\Http\Controllers\Admin\Concerns\SalesDocumentPage;
use Modules\Accounting\Http\Requests\Admin\SalesInvoiceRequest;
use Modules\Accounting\Repositories\Contracts\SalesInvoiceRepositoryInterface;
use Modules\Accounting\Services\AccountingPdfService;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Support\CustomerProfitMargin;
use Modules\Accounting\Support\ItemsGridState;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\PaymentAllocationPanel;
use Modules\Accounting\Support\SalesServiceLine;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\UserCellData;
use Modules\Items\Support\RecordInfo;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * فواتير البيع: مسودة تُعدَّل، ثم ترحيل (قيد الايراد وخروج البضاعة من المخزن بكلفتها عبر
 * InvoiceStockService) او الغاء الفاتورة المرحّلة بقيد عكسي. فاتورة من طلبية: اصنافها مقفلة،
 * والوزن الفعلي والسعر والخصم تُدخل. القواعد كلها في SalesInvoiceService.
 */
class SalesInvoiceController extends Controller
{
    use SalesDocumentPage;

    public function __construct(
        private readonly SalesInvoiceRepositoryInterface $invoices,
        private readonly SalesInvoiceService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/SalesInvoice/Index', [
            'table' => AdminTable::for($this->invoices->tableQuery())
                ->search(['reference_no', 'notes'], null)
                ->filters(
                    AdminFilter::text('reference_no'),
                    $this->referenceFilter('sales_order', 'salesOrder'),
                    $this->partyFilter(),
                    AdminFilter::exact('warehouse', 'warehouse_id'),
                    AdminFilter::text('notes'),
                    AdminFilter::exact('status'),
                    AdminFilter::exact('currency', 'currency_id'),
                    AdminFilter::exact('created_by'),
                    $this->itemsFilter('barcode'),
                    $this->itemsFilter('description'),
                    $this->stateFilter('items_state', [
                        'with_items' => fn (Builder $query) => $query->whereHas('items'),
                        'without_items' => fn (Builder $query) => $query->whereDoesntHave('items'),
                    ]),
                    $this->stateFilter('journal_state', [
                        'with_journal' => fn (Builder $query) => $query->whereNotNull('journal_entry_id'),
                        'without_journal' => fn (Builder $query) => $query->whereNull('journal_entry_id'),
                    ]),
                    AllowedFilter::trashed(),
                    ...AdminFilter::dateRange('invoice_date'),
                    ...AdminFilter::dateRange('due_date'),
                    ...AdminFilter::numberRange('sub_total'),
                    ...AdminFilter::numberRange('invoice_discount'),
                    ...AdminFilter::numberRange('total_amount'),
                )
                ->sorts('reference_no', 'invoice_date', 'due_date', 'total_amount', 'status', 'id')
                ->defaultSort('-invoice_date')
                ->rows(fn (SalesInvoice $invoice) => [
                    'id' => $invoice->id,
                    'reference_no' => $invoice->reference_no,
                    'invoice_date' => $invoice->invoice_date?->format('d/m/Y'),
                    'due_date' => $invoice->due_date?->format('d/m/Y'),
                    'sales_order' => $invoice->salesOrder?->reference_no,
                    'client' => UserCellData::of($invoice->client),
                    'warehouse' => $invoice->warehouse?->name,
                    'currency' => $invoice->currency?->code,
                    'tax' => $invoice->tax?->name,
                    'total_amount' => number_format((float) $invoice->total_amount, 2),
                    'total_amount_full' => Number::money($invoice->total_amount),
                    'status' => StatusBadge::of($invoice->status),
                    'notes' => $invoice->notes,
                    'deleted' => $invoice->trashed(),
                    'editable' => ! $invoice->trashed() && $invoice->canBeEdited(),
                ]),
            'statusOptions' => SelectOptions::from(SalesInvoiceStatus::options()),
            'currencyOptions' => SelectOptions::currencies(),
            'warehouseOptions' => $this->documentOptions()['warehouseOptions'],
            'userOptions' => $this->userOptions(),
        ]);
    }

    public function add(): Response
    {
        $currencyId = $this->baseCurrencyId();
        $today = now()->toDateString();

        return Inertia::render('Accounting/SalesInvoice/Add', [
            'invoice' => [
                'sales_order_id' => null,
                'client_id' => null,
                'branch_id' => null,
                'warehouse_id' => null,
                'invoice_date' => $today,
                'due_date' => null,
                'currency_id' => $currencyId,
                'exchange_rate' => $this->exchangeRate($currencyId, $today) ?? '1',
                'tax_id' => null,
                'invoice_discount' => '0',
                'notes' => null,
                'items' => [],
                'services' => [],
            ],
            'referenceNo' => app(ReferenceNumberService::class)->preview('sales_invoice', now(), ['prefix' => 'SINV']),
            'statusLabel' => SalesInvoiceStatus::DRAFT->getLabel(),
            ...$this->formOptions(),
        ]);
    }

    public function store(SalesInvoiceRequest $request): RedirectResponse
    {
        try {
            $invoice = $this->service->saveForm(null, $request->formData());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.sales-invoices.edit', $invoice->id)->with('success', __('Sales invoice created successfully'));
    }

    /**
     * الطلبيات التي تُفوتر للزبون المختار (RemoteSelect).
     */
    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->service->orderOptions($data['client_id'] ?? null, $data['q'] ?? null));
    }

    /**
     * ما يأخذه النموذج من الطلبية المختارة: الرأس ومجاميعها؛ بنودها تنتقل عند الحفظ.
     */
    public function orderDefaults(Request $request): JsonResponse
    {
        $data = $request->validate(['sales_order_id' => ['required', 'integer'], 'invoice_date' => ['nullable', 'date']]);
        $order = SalesOrder::query()->with('client')->findOrFail($data['sales_order_id']);

        return response()->json([
            ...$this->service->orderDefaults($order, $data['invoice_date'] ?? null),
            'client' => $order->client ? ['value' => $order->client->id, 'label' => (string) $order->client->name] : null,
        ]);
    }

    /**
     * صفحة الفاتورة: نموذج ما دامت مسودة ولصاحب صلاحية التعديل، ثم عرض للقراءة مع ترحيلها او
     * الغائها ولوحة ما سُدِّد منها.
     */
    public function edit(int $salesInvoice): Response
    {
        $invoice = $this->invoices->findForPage($salesInvoice);
        $editable = $invoice->canBeEdited() && (bool) auth()->user()?->can('Sales Invoice Update');

        return Inertia::render('Accounting/SalesInvoice/Edit', [
            'invoice' => [
                'sales_order_id' => $invoice->sales_order_id,
                'client_id' => $invoice->client_id,
                'branch_id' => $invoice->branch_id,
                'warehouse_id' => $invoice->warehouse_id,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'currency_id' => $invoice->currency_id,
                'exchange_rate' => Number::clean($invoice->exchange_rate, 8),
                'tax_id' => $invoice->tax_id,
                'invoice_discount' => Number::money($invoice->invoice_discount),
                'notes' => $invoice->notes,
                'items' => ItemsGridState::rows(
                    $invoice->items->reject(fn ($item): bool => $item->isService())->values(),
                    ['sales_order_item_id'],
                    ['sales_order_item_piece_id'],
                    quantityIsWeight: true,
                ),
                'services' => SalesServiceLine::rows($invoice->items, 'sales_order_item_id'),
            ],
            'record' => [
                'id' => $invoice->id,
                'reference_no' => $invoice->reference_no,
                'status' => StatusBadge::of($invoice->status),
                'client' => $invoice->client ? ['value' => $invoice->client->id, 'label' => (string) $invoice->client->name] : null,
                'margin_hint' => CustomerProfitMargin::hint($invoice->client_id),
                'invoice_date' => $invoice->invoice_date?->format('d/m/Y'),
                'editable' => $editable,
                'from_order' => filled($invoice->sales_order_id),
                'sales_order' => $invoice->salesOrder ? ['id' => $invoice->salesOrder->id, 'reference_no' => $invoice->salesOrder->reference_no] : null,
                // خدمة بالكيلوغرام: وزن مواد العميل في الطلبية المصدر يقترح كميتها.
                'materials_weight' => round((float) ($invoice->salesOrder?->customerMaterials->sum('weight') ?? 0), 4),
                'can_post' => $invoice->canBePosted(),
                'can_cancel' => $invoice->isPosted(),
                'can_delete' => $invoice->canBeEdited(),
                'cancelled' => $invoice->isCancelled(),
                'cancelled_at' => $invoice->cancelled_at?->format('d/m/Y H:i'),
                'cancellation_reason' => $invoice->cancellation_reason,
            ],
            'allocationPanel' => $invoice->isPosted() || $invoice->isCancelled() ? PaymentAllocationPanel::of($invoice) : null,
            ...$this->formOptions(),
        ]);
    }

    public function update(SalesInvoiceRequest $request, int $salesInvoice): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->saveForm($this->find($salesInvoice), $request->formData()), __('Sales invoice updated successfully'));
    }

    /**
     * يحفظ النموذج (لصاحب صلاحية التعديل) ثم يرحّل: قيد الايراد وخروج البضاعة من المخزن.
     */
    public function post(int $salesInvoice): RedirectResponse
    {
        $data = request()->has('client_id') && auth()->user()?->can('Sales Invoice Update')
            ? app(SalesInvoiceRequest::class)->formData()
            : null;

        return $this->attempt(
            fn () => $this->service->saveThen($this->find($salesInvoice), $data, fn (SalesInvoice $invoice) => $this->service->post($invoice)),
            __('Sales invoice posted successfully'),
        );
    }

    public function cancel(Request $request, int $salesInvoice): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->cancel($this->find($salesInvoice), $data['reason'] ?? null), __('Sales invoice cancelled successfully.'));
    }

    public function destroy(int $salesInvoice): RedirectResponse
    {
        try {
            $this->service->delete($this->find($salesInvoice));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.sales-invoices.index')->with('success', __('Sales invoice deleted successfully'));
    }

    public function info(int $salesInvoice): JsonResponse
    {
        return response()->json(RecordInfo::of($this->find($salesInvoice)));
    }

    public function pdf(int $salesInvoice, AccountingPdfService $pdf): mixed
    {
        return $pdf->salesInvoice($this->find($salesInvoice));
    }

    private function find(int $id): SalesInvoice
    {
        /** @var SalesInvoice */
        return $this->invoices->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            ...$this->documentOptions(),
            'serviceOptions' => SalesServiceLine::options(),
        ];
    }
}
