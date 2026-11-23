<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\SalesQuotation;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\SalesQuotationStatus;
use Modules\Accounting\Http\Controllers\Admin\Concerns\DeletesManyDocuments;
use Modules\Accounting\Http\Requests\Admin\SalesQuotationRequest;
use Modules\Accounting\Repositories\Contracts\SalesQuotationRepositoryInterface;
use Modules\Accounting\Services\AccountingPdfService;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Services\SalesQuotationService;
use Modules\Accounting\Support\CustomerProfitMargin;
use Modules\Accounting\Support\ItemsGridState;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Entities\User;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\UserCellData;
use Modules\Core\Support\RecordInfo;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * عروض اسعار البيع: مسودة او معتمد يُعدَّلان، ثم تحويل الى طلبية بيع او الغاء. البنود في جدول
 * الاصناف الموحد (Pages/parts/items-grid)، والقواعد كلها في SalesQuotationService.
 */
class SalesQuotationController extends Controller
{
    use DeletesManyDocuments;

    public function __construct(
        private readonly SalesQuotationRepositoryInterface $quotations,
        private readonly SalesQuotationService $service,
        private readonly CurrencyExchangeRateService $rates,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/SalesQuotation/Index', [
            'table' => AdminTable::for($this->quotations->tableQuery())
                ->search(['reference_no', 'notes'], null)
                ->filters(
                    AdminFilter::text('reference_no'),
                    $this->clientFilter(),
                    AdminFilter::exact('warehouse', 'warehouse_id'),
                    AdminFilter::text('notes'),
                    AdminFilter::exact('status'),
                    AdminFilter::exact('currency', 'currency_id'),
                    AdminFilter::exact('created_by'),
                    $this->itemsFilter('barcode'),
                    $this->itemsFilter('description'),
                    $this->validityFilter(),
                    $this->stateFilter(),
                    AllowedFilter::trashed(),
                    ...AdminFilter::dateRange('quotation_date'),
                    ...AdminFilter::dateRange('valid_until'),
                    ...AdminFilter::numberRange('sub_total'),
                    ...AdminFilter::numberRange('quotation_discount'),
                    ...AdminFilter::numberRange('total_amount'),
                )
                ->sorts('reference_no', 'quotation_date', 'valid_until', 'total_amount', 'status', 'id')
                ->defaultSort('-quotation_date')
                ->withCreator()
                ->rows(fn (SalesQuotation $quotation) => [
                    'id' => $quotation->id,
                    'reference_no' => $quotation->reference_no,
                    'quotation_date' => $quotation->quotation_date?->format('d/m/Y'),
                    'valid_until' => $quotation->valid_until?->format('d/m/Y'),
                    'client' => UserCellData::of($quotation->client),
                    'warehouse' => $quotation->warehouse?->name,
                    'currency' => $quotation->currency?->code,
                    'total_amount' => number_format((float) $quotation->total_amount, 2),
                    'total_amount_full' => Number::money($quotation->total_amount),
                    'status' => StatusBadge::of($quotation->status),
                    'notes' => $quotation->notes,
                    'deleted' => $quotation->trashed(),
                    'editable' => ! $quotation->trashed() && $quotation->canBeEdited(),
                    'can_convert' => ! $quotation->trashed() && $quotation->canBeConverted(),
                    'can_delete' => ! $quotation->trashed() && $quotation->canBeDeleted(),
                ]),
            'statusOptions' => SelectOptions::from(SalesQuotationStatus::options()),
            'currencyOptions' => SelectOptions::currencies(),
            'warehouseOptions' => SelectOptions::from(Warehouse::options()),
            'userOptions' => SelectOptions::from(User::query()->orderBy('name')->limit(50)->pluck('name', 'id')->all()),
        ]);
    }

    public function add(): Response
    {
        $currencyId = $this->rates->baseCurrencyId();
        $today = now()->toDateString();

        return Inertia::render('Accounting/SalesQuotation/Add', [
            'quotation' => [
                'client_id' => null,
                'branch_id' => null,
                'warehouse_id' => null,
                'quotation_date' => $today,
                'valid_until' => null,
                'currency_id' => $currencyId,
                'exchange_rate' => $this->rate($currencyId, $today) ?? '1',
                'tax_id' => null,
                'quotation_discount' => '0',
                'notes' => null,
                'items' => [],
            ],
            'referenceNo' => app(ReferenceNumberService::class)->preview('sales_quotation', now(), ['prefix' => 'SQUO']),
            'statusLabel' => SalesQuotationStatus::DRAFT->getLabel(),
            ...$this->formOptions(),
        ]);
    }

    public function store(SalesQuotationRequest $request): RedirectResponse
    {
        try {
            $quotation = $this->service->create($this->data($request));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.sales-quotations.edit', $quotation->id)->with('success', __('Sales quotation created successfully'));
    }

    /**
     * صفحة العرض: نموذج تعديل ما دام مسودة او معتمداً، ثم عرض للقراءة مع رابط طلبيته.
     */
    public function edit(int $salesQuotation): Response
    {
        $quotation = $this->quotations->findForPage($salesQuotation);
        $order = $quotation->salesOrders->sortByDesc('id')->first();

        return Inertia::render('Accounting/SalesQuotation/Edit', [
            'quotation' => [
                'client_id' => $quotation->client_id,
                'branch_id' => $quotation->branch_id,
                'warehouse_id' => $quotation->warehouse_id,
                'quotation_date' => $quotation->quotation_date?->toDateString(),
                'valid_until' => $quotation->valid_until?->toDateString(),
                'currency_id' => $quotation->currency_id,
                'exchange_rate' => Number::clean($quotation->exchange_rate, 8),
                'tax_id' => $quotation->tax_id,
                'quotation_discount' => Number::money($quotation->quotation_discount),
                'notes' => $quotation->notes,
                // كمية سطر الوزن كيلوغرامات: السطر القديم بلا قطع يُعرض بها وزناً فعلياً.
                'items' => ItemsGridState::rows($quotation->items, quantityIsWeight: true),
            ],
            'record' => [
                'id' => $quotation->id,
                'reference_no' => $quotation->reference_no,
                'status' => StatusBadge::of($quotation->status),
                'client' => $quotation->client ? ['value' => $quotation->client->id, 'label' => (string) $quotation->client->name] : null,
                'margin_hint' => CustomerProfitMargin::hint($quotation->client_id),
                'editable' => $quotation->canBeEdited(),
                'can_convert' => $quotation->canBeConverted(),
                'can_delete' => $quotation->canBeDeleted(),
                'sales_order' => $order ? ['id' => $order->id, 'reference_no' => $order->reference_no] : null,
                'totals' => [
                    'sub_total' => Number::money($quotation->sub_total),
                    'items_discount' => Number::money($quotation->items_discount),
                    'tax_amount' => Number::money($quotation->tax_amount),
                    'total_amount' => Number::money($quotation->total_amount),
                ],
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(SalesQuotationRequest $request, int $salesQuotation): RedirectResponse
    {
        try {
            $this->service->update($this->find($salesQuotation), $this->data($request));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Sales quotation updated successfully'));
    }

    /**
     * يحفظ النموذج ثم يعتمد العرض، كما كان زر «اعتماد».
     */
    public function approve(SalesQuotationRequest $request, int $salesQuotation): RedirectResponse
    {
        try {
            $this->service->saveAndApprove($this->find($salesQuotation), $this->data($request));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Sales quotation approved successfully'));
    }

    /**
     * طلبية بيع من العرض. من صفحة العرض تُرسل البيانات فتُحفظ اولاً؛ من القائمة يُحوَّل كما هو.
     */
    public function convert(int $salesQuotation): RedirectResponse
    {
        $quotation = $this->find($salesQuotation);
        $data = request()->has('client_id') ? $this->data(app(SalesQuotationRequest::class)) : null;

        try {
            $order = $this->service->convertToSalesOrder($quotation, $data);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.sales-orders.edit', ['salesOrder' => $order->id])->with('success', __('Sales order created from quotation successfully'));
    }

    public function cancel(int $salesQuotation): RedirectResponse
    {
        return $this->attempt(fn (SalesQuotation $quotation) => $this->service->cancel($quotation), $salesQuotation, __('Sales quotation cancelled successfully'));
    }

    /**
     * من القائمة او من صفحة العرض نفسه: بعد الحذف تعود القائمة، فصفحة العرض لم تعد موجودة.
     */
    public function destroy(int $salesQuotation): RedirectResponse
    {
        try {
            $this->service->delete($this->find($salesQuotation));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.sales-quotations.index')->with('success', __('Sales quotation deleted successfully'));
    }

    /**
     * The list's rows, deleted ones included (its «deleted» filter shows them; they are refused).
     */
    protected function bulkDeleteQuery(): Builder
    {
        return $this->quotations->tableQuery()->withTrashed();
    }

    /**
     * @param  SalesQuotation  $document
     */
    protected function deleteDocument(Model $document): void
    {
        $this->service->delete($document);
    }

    public function info(int $salesQuotation): JsonResponse
    {
        return response()->json(RecordInfo::of($this->quotations->query()->withTrashed()->findOrFail($salesQuotation)));
    }

    public function pdf(int $salesQuotation, AccountingPdfService $pdf): mixed
    {
        return $pdf->salesQuotation($this->find($salesQuotation));
    }

    private function find(int $id): SalesQuotation
    {
        /** @var SalesQuotation */
        return $this->quotations->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(SalesQuotationRequest $request): array
    {
        return [...$request->validated(), 'items' => $request->gridItems()];
    }

    /**
     * @param  callable(SalesQuotation): mixed  $action
     */
    private function attempt(callable $action, int $id, string $message): RedirectResponse
    {
        try {
            $action($this->find($id));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $message);
    }

    private function rate(?int $currencyId, string $date): ?string
    {
        $rate = $this->rates->rateFor($currencyId, $date);

        return $rate ? number_format($rate, 8, '.', '') : null;
    }

    /**
     * الطرف بالاسم او الهاتف او البريد.
     */
    private function clientFilter(): AllowedFilter
    {
        return AllowedFilter::callback('client', function (Builder $query, mixed $value): void {
            $term = '%'.addcslashes(trim((string) $value), '%_\\').'%';

            $query->whereHas('client', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', $term)
                ->orWhereLike('phone', $term)
                ->orWhereLike('email', $term)));
        });
    }

    /**
     * عروض فيها سطر يطابق باركوده او وصفه النص المكتوب.
     */
    private function itemsFilter(string $column): AllowedFilter
    {
        return AllowedFilter::callback("item_{$column}", function (Builder $query, mixed $value) use ($column): void {
            $term = '%'.addcslashes(trim((string) $value), '%_\\').'%';

            $query->whereHas('items', fn (Builder $items) => $items->whereLike($column, $term));
        });
    }

    /**
     * صلاحية العرض اليوم: ساري، منتهٍ، او بلا تاريخ.
     */
    private function validityFilter(): AllowedFilter
    {
        return AllowedFilter::callback('validity', function (Builder $query, mixed $value): void {
            $today = now()->toDateString();

            match ((string) $value) {
                'valid' => $query->whereDate('valid_until', '>=', $today),
                'expired' => $query->whereDate('valid_until', '<', $today),
                'no_date' => $query->whereNull('valid_until'),
                default => null,
            };
        });
    }

    /**
     * حالة المستند: بسطور او بلا سطور، وبطلبية بيع او بلا طلبية.
     */
    private function stateFilter(): AllowedFilter
    {
        return AllowedFilter::callback('document_state', function (Builder $query, mixed $value): void {
            match ((string) $value) {
                'with_items' => $query->whereHas('items'),
                'without_items' => $query->whereDoesntHave('items'),
                'with_order' => $query->whereHas('salesOrders'),
                'without_order' => $query->whereDoesntHave('salesOrders'),
                default => null,
            };
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'warehouseOptions' => SelectOptions::from(Warehouse::options()),
            'currencyOptions' => SelectOptions::currencies(),
            'taxOptions' => Tax::query()->active()->orderBy('name')->get()
                ->map(fn (Tax $tax) => ['value' => $tax->id, 'label' => $tax->name.' - '.number_format((float) $tax->rate, 2).'%', 'rate' => Number::clean($tax->rate)])
                ->values()
                ->all(),
        ];
    }
}
