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
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Http\Controllers\Admin\Concerns\PurchaseDocumentPage;
use Modules\Accounting\Http\Requests\Admin\PurchaseInvoiceRequest;
use Modules\Accounting\Repositories\Contracts\PurchaseInvoiceRepositoryInterface;
use Modules\Accounting\Services\AccountingPdfService;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Support\ItemsGridState;
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
 * فواتير الشراء: يدوية او من طلبية شراء (باسعار عرض السعر المعتمد ان وُجد). الفاتورة من طلبية
 * اصنافها اصناف الطلبية ويُدخل السعر والخصم والوزن الفعلي؛ الترحيل قيد ودخول البضاعة المخزون
 * بطبقات كلفة FIFO؛ الالغاء قيد عكسي وعكس حركة المخزون. القواعد كلها في PurchaseInvoiceService.
 */
class PurchaseInvoiceController extends Controller
{
    use PurchaseDocumentPage;

    public function __construct(
        private readonly PurchaseInvoiceRepositoryInterface $invoices,
        private readonly PurchaseInvoiceService $service,
        private readonly CurrencyExchangeRateService $rates,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/PurchaseInvoice/Index', [
            'table' => AdminTable::for($this->invoices->tableQuery())
                ->search(['reference_no', 'notes'], null)
                ->filters(
                    AdminFilter::text('reference_no'),
                    $this->partyFilter(),
                    AdminFilter::exact('warehouse', 'warehouse_id'),
                    AdminFilter::text('notes'),
                    AdminFilter::exact('status'),
                    AdminFilter::exact('currency', 'currency_id'),
                    AdminFilter::exact('tax', 'tax_id'),
                    AdminFilter::exact('created_by'),
                    $this->referenceFilter('purchase_order', 'purchaseOrder'),
                    $this->itemsFilter('barcode'),
                    $this->itemsFilter('description'),
                    $this->taxStateFilter(),
                    $this->stateFilter(),
                    $this->dueFilter(),
                    $this->journalFilter(),
                    AllowedFilter::trashed(),
                    ...AdminFilter::dateRange('invoice_date'),
                    ...AdminFilter::dateRange('due_date'),
                    ...AdminFilter::dateRange('created_at'),
                    ...AdminFilter::numberRange('exchange_rate'),
                    ...AdminFilter::numberRange('tax_rate'),
                    ...AdminFilter::numberRange('sub_total'),
                    ...AdminFilter::numberRange('items_discount'),
                    ...AdminFilter::numberRange('invoice_discount'),
                    ...AdminFilter::numberRange('tax_amount'),
                    ...AdminFilter::numberRange('total_amount'),
                    ...$this->relationRange('item_quantity', 'items', 'quantity'),
                    ...$this->relationRange('item_unit_price', 'items', 'unit_price'),
                    ...$this->relationRange('item_discount_rate', 'items', 'discount_rate'),
                    ...$this->relationRange('item_discount_amount', 'items', 'discount_amount'),
                    ...$this->relationRange('item_line_total', 'items', 'line_total'),
                    ...$this->relationRange('posted_at', 'journalEntry', 'posted_at', date: true),
                )
                ->sorts('reference_no', 'invoice_date', 'due_date', 'total_amount', 'status', 'id')
                ->defaultSort('-invoice_date')
                ->withCreator()
                ->rows(fn (PurchaseInvoice $invoice) => [
                    'id' => $invoice->id,
                    'reference_no' => $invoice->reference_no,
                    'invoice_date' => $invoice->invoice_date?->format('d/m/Y'),
                    'due_date' => $invoice->due_date?->format('d/m/Y'),
                    'client' => UserCellData::of($invoice->client),
                    'warehouse' => $invoice->warehouse?->name,
                    'currency' => $invoice->currency?->code,
                    'total_amount' => number_format((float) $invoice->total_amount, 2),
                    'total_amount_full' => Number::money($invoice->total_amount),
                    'exchange_rate' => number_format((float) $invoice->exchange_rate, 8),
                    'tax' => $invoice->tax?->name,
                    'tax_rate' => number_format((float) $invoice->tax_rate, 2).'%',
                    'journal_entry' => $invoice->journalEntry?->entry_number,
                    'status' => StatusBadge::of($invoice->status),
                    'notes' => $invoice->notes,
                    'deleted' => $invoice->trashed(),
                    'editable' => ! $invoice->trashed() && $invoice->canBeEdited(),
                ]),
            'statusOptions' => SelectOptions::from(PurchaseInvoiceStatus::options()),
            'currencyOptions' => SelectOptions::currencies(),
            'userOptions' => $this->userOptions(),
            ...collect($this->documentOptions())->only(['warehouseOptions', 'taxOptions'])->all(),
        ]);
    }

    public function add(): Response
    {
        $currencyId = $this->rates->baseCurrencyId();
        $today = now()->toDateString();

        return Inertia::render('Accounting/PurchaseInvoice/Add', [
            'invoice' => [
                'client_id' => null,
                'purchase_order_id' => null,
                'branch_id' => null,
                'warehouse_id' => null,
                'invoice_date' => $today,
                'due_date' => null,
                'currency_id' => $currencyId,
                'exchange_rate' => $this->rate($currencyId, $today) ?? '1',
                'tax_id' => null,
                'invoice_discount' => '0',
                'notes' => null,
                'items' => [],
            ],
            'referenceNo' => app(ReferenceNumberService::class)->preview('purchase_invoice', now(), ['prefix' => 'PINV']),
            'statusLabel' => PurchaseInvoiceStatus::DRAFT->getLabel(),
            ...$this->documentOptions(),
        ]);
    }

    /**
     * طلبيات المورد التي يمكن فوترتها (حقل «طلبية الشراء» في صفحة الاضافة).
     */
    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:255']]);

        return response()->json(filled($data['client_id'] ?? null) ? $this->invoices->invoiceableOrders((int) $data['client_id'], (string) ($data['q'] ?? '')) : []);
    }

    /**
     * ما تُعبأ به الصفحة عند اختيار الطلبية: الرأس وسطورها باسعار عرض السعر المعتمد ان وُجد.
     * عرض سعر مسودة يمنع الفوترة (رسالة بدل البيانات).
     */
    public function orderDraft(Request $request): JsonResponse
    {
        $data = $request->validate(['purchase_order_id' => ['required', 'integer'], 'invoice_date' => ['nullable', 'date']]);
        $order = PurchaseOrder::query()->with(['tax', 'items.product.translations', 'items.pieces'])->find($data['purchase_order_id']);

        if (! $order) {
            return response()->json(['message' => __('Purchase order not found.')], 422);
        }

        try {
            return response()->json($this->service->orderEntry($order, $data['invoice_date'] ?? null));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function store(PurchaseInvoiceRequest $request): RedirectResponse
    {
        try {
            $invoice = $this->service->saveFromEntry(null, $request->payload());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.purchase-invoices.edit', $invoice->id)->with('success', __('Purchase invoice created successfully'));
    }

    public function edit(Request $request, int $purchaseInvoice): Response
    {
        $invoice = $this->invoices->findForPage($purchaseInvoice);
        $editable = $invoice->canBeEdited() && $request->user()->can('Purchase Invoice Update');

        return Inertia::render('Accounting/PurchaseInvoice/Edit', [
            'invoice' => [
                'client_id' => $invoice->client_id,
                'purchase_order_id' => $invoice->purchase_order_id,
                'branch_id' => $invoice->branch_id,
                'warehouse_id' => $invoice->warehouse_id,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'currency_id' => $invoice->currency_id,
                'exchange_rate' => Number::clean($invoice->exchange_rate, 8),
                'tax_id' => $invoice->tax_id,
                'invoice_discount' => Number::money($invoice->invoice_discount),
                'notes' => $invoice->notes,
                // كمية سطر الوزن كيلوغرامات: السطر القديم بلا قطع يُعرض بها وزناً فعلياً.
                'items' => ItemsGridState::rows($invoice->items, ['purchase_order_item_id'], ['purchase_order_item_piece_id'], quantityIsWeight: true),
            ],
            'record' => [
                'id' => $invoice->id,
                'reference_no' => $invoice->reference_no,
                'status' => StatusBadge::of($invoice->status),
                'client' => $invoice->client ? ['value' => $invoice->client->id, 'label' => (string) $invoice->client->name] : null,
                'editable' => $editable,
                // فاتورة من طلبية: اصنافها واصنافها الفرعية كما في الطلبية، والسعر والخصم والوزن يُدخلان.
                'items_editable' => $editable && blank($invoice->purchase_order_id),
                'can_post' => $invoice->canBePosted(),
                'can_cancel' => $invoice->isPosted(),
                'can_delete' => $invoice->canBeEdited(),
                'posted' => $invoice->isPosted(),
                'cancelled' => $invoice->isCancelled(),
                'cancelled_at' => $invoice->cancelled_at?->format('d/m/Y H:i'),
                'cancellation_reason' => $invoice->cancellation_reason,
                'purchase_order' => $invoice->purchaseOrder ? ['id' => $invoice->purchaseOrder->id, 'reference_no' => $invoice->purchaseOrder->reference_no] : null,
                'purchase_quotation' => $invoice->purchaseQuotation ? ['id' => $invoice->purchaseQuotation->id, 'reference_no' => $invoice->purchaseQuotation->reference_no] : null,
                'journal_entry' => $invoice->journalEntry ? ['id' => $invoice->journalEntry->id, 'entry_number' => $invoice->journalEntry->entry_number] : null,
            ],
            'allocationPanel' => $invoice->isPosted() || $invoice->isCancelled() ? PaymentAllocationPanel::of($invoice) : null,
            ...$this->documentOptions(),
        ]);
    }

    public function update(PurchaseInvoiceRequest $request, int $purchaseInvoice): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->saveFromEntry($this->find($purchaseInvoice), $request->payload()), __('Purchase invoice updated successfully'));
    }

    /**
     * يحفظ المسودة بما أُدخل ثم يرحّلها: قيد الفاتورة ودخول البضاعة المخزون.
     */
    public function post(int $purchaseInvoice): RedirectResponse
    {
        $invoice = $this->find($purchaseInvoice);
        $data = request()->has('client_id') && $invoice->canBeEdited() && request()->user()->can('Purchase Invoice Update')
            ? app(PurchaseInvoiceRequest::class)->payload()
            : null;

        return $this->attempt(fn () => $this->service->saveAndPost($invoice, $data), __('Purchase invoice posted successfully'));
    }

    public function cancel(Request $request, int $purchaseInvoice): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->cancel($this->find($purchaseInvoice), $data['reason'] ?? null), __('Purchase invoice cancelled successfully.'));
    }

    public function destroy(int $purchaseInvoice): RedirectResponse
    {
        try {
            $this->service->delete($this->find($purchaseInvoice));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.purchase-invoices.index')->with('success', __('Purchase invoice deleted successfully'));
    }

    public function info(int $purchaseInvoice): JsonResponse
    {
        return response()->json(RecordInfo::of($this->invoices->query()->withTrashed()->findOrFail($purchaseInvoice)));
    }

    public function print(int $purchaseInvoice, AccountingPdfService $pdf): mixed
    {
        return $pdf->purchaseInvoice($this->find($purchaseInvoice));
    }

    private function find(int $id): PurchaseInvoice
    {
        /** @var PurchaseInvoice */
        return $this->invoices->findOrFail($id);
    }

    private function rate(?int $currencyId, string $date): ?string
    {
        $rate = $this->rates->rateFor($currencyId, $date);

        return $rate ? number_format($rate, 8, '.', '') : null;
    }

    /**
     * بضريبة او بلا ضريبة.
     */
    private function taxStateFilter(): AllowedFilter
    {
        return AllowedFilter::callback('tax_state', fn (Builder $query, mixed $value) => match ((string) $value) {
            'with_tax' => $query->whereNotNull('tax_id'),
            'without_tax' => $query->whereNull('tax_id'),
            default => null,
        });
    }

    /**
     * بسطور او بلا سطور، وبقيد او بلا قيد.
     */
    private function stateFilter(): AllowedFilter
    {
        return AllowedFilter::callback('document_state', fn (Builder $query, mixed $value) => match ((string) $value) {
            'with_items' => $query->whereHas('items'),
            'without_items' => $query->whereDoesntHave('items'),
            'with_journal' => $query->whereNotNull('journal_entry_id'),
            'without_journal' => $query->whereNull('journal_entry_id'),
            default => null,
        });
    }

    /**
     * الاستحقاق اليوم: متأخرة، تستحق اليوم، قادمة، او بلا تاريخ.
     */
    private function dueFilter(): AllowedFilter
    {
        return AllowedFilter::callback('due_state', function (Builder $query, mixed $value): void {
            $today = now()->toDateString();

            match ((string) $value) {
                'overdue' => $query->whereDate('due_date', '<', $today),
                'due_today' => $query->whereDate('due_date', $today),
                'upcoming' => $query->whereDate('due_date', '>', $today),
                'no_due_date' => $query->whereNull('due_date'),
                default => null,
            };
        });
    }

    /**
     * فواتير قيدها يطابق رقمه النص المكتوب.
     */
    private function journalFilter(): AllowedFilter
    {
        return AllowedFilter::callback('journal_entry', fn (Builder $query, mixed $value) => $query->whereHas('journalEntry', fn (Builder $entry) => $entry->whereLike('entry_number', $this->likeTerm($value))));
    }

    /**
     * مدى على عمود في علاقة: سطر فيه الكمية (او السعر...) بين حدين، او قيد رُحّل بين تاريخين.
     *
     * @return array{0: AllowedFilter, 1: AllowedFilter}
     */
    private function relationRange(string $name, string $relation, string $column, bool $date = false): array
    {
        $filter = fn (string $suffix, string $operator): AllowedFilter => AllowedFilter::callback("{$name}_{$suffix}", function (Builder $query, mixed $value) use ($relation, $column, $operator, $date): void {
            $valid = $date ? (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) : is_numeric($value);

            if ($valid) {
                $query->whereHas($relation, fn (Builder $related) => $date ? $related->whereDate($column, $operator, $value) : $related->where($column, $operator, $value));
            }
        });

        return [$filter('from', '>='), $filter('to', '<=')];
    }
}
