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
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\SalesQuotation;
use Modules\Accounting\Enums\SalesOrderFulfillmentType;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Http\Controllers\Admin\Concerns\SalesDocumentPage;
use Modules\Accounting\Http\Requests\Admin\SalesOrderRequest;
use Modules\Accounting\Repositories\Contracts\SalesOrderRepositoryInterface;
use Modules\Accounting\Services\AccountingPdfService;
use Modules\Accounting\Services\DeliveryNoteService;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\SalesOrderService;
use Modules\Accounting\Support\CustomerProfitMargin;
use Modules\Accounting\Support\ItemsGridState;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\PartySelect;
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
 * طلبيات البيع: مسودة ← مؤكدة ← قيد التجهيز ← مفوترة، او ملغاة. البنود في جدول الاصناف الموحد
 * (Pages/parts/items-grid) مع قسمي الخدمات ومواد العميل؛ القواعد كلها في SalesOrderService،
 * والفاتورة من الطلبية في SalesInvoiceService. كل اجراء يحفظ تعديلات النموذج اولاً.
 */
class SalesOrderController extends Controller
{
    use SalesDocumentPage;

    public function __construct(
        private readonly SalesOrderRepositoryInterface $orders,
        private readonly SalesOrderService $service,
    ) {}

    public function index(): Response
    {
        $today = now()->toDateString();

        return Inertia::render('Accounting/SalesOrder/Index', [
            'table' => AdminTable::for($this->orders->tableQuery())
                ->search(['reference_no', 'notes'], null)
                ->filters(
                    AdminFilter::text('reference_no'),
                    $this->referenceFilter('sales_quotation', 'salesQuotation'),
                    $this->partyFilter(),
                    AdminFilter::exact('warehouse', 'warehouse_id'),
                    AdminFilter::text('notes'),
                    AdminFilter::exact('status'),
                    AdminFilter::exact('currency', 'currency_id'),
                    AdminFilter::exact('created_by'),
                    $this->itemsFilter('barcode'),
                    $this->itemsFilter('description'),
                    $this->stateFilter('delivery_state', [
                        'overdue' => fn (Builder $query) => $query->whereDate('expected_delivery_date', '<', $today),
                        'due_today' => fn (Builder $query) => $query->whereDate('expected_delivery_date', $today),
                        'upcoming' => fn (Builder $query) => $query->whereDate('expected_delivery_date', '>', $today),
                        'no_date' => fn (Builder $query) => $query->whereNull('expected_delivery_date'),
                    ]),
                    $this->stateFilter('items_state', [
                        'with_items' => fn (Builder $query) => $query->whereHas('items'),
                        'without_items' => fn (Builder $query) => $query->whereDoesntHave('items'),
                    ]),
                    $this->stateFilter('source_state', [
                        'from_quotation' => fn (Builder $query) => $query->whereNotNull('sales_quotation_id'),
                        'manual' => fn (Builder $query) => $query->whereNull('sales_quotation_id'),
                    ]),
                    AllowedFilter::trashed(),
                    ...AdminFilter::dateRange('order_date'),
                    ...AdminFilter::dateRange('expected_delivery_date'),
                    ...AdminFilter::numberRange('sub_total'),
                    ...AdminFilter::numberRange('order_discount'),
                    ...AdminFilter::numberRange('total_amount'),
                )
                ->sorts('reference_no', 'order_date', 'expected_delivery_date', 'total_amount', 'status', 'id')
                ->defaultSort('-order_date')
                ->rows(fn (SalesOrder $order) => [
                    'id' => $order->id,
                    'reference_no' => $order->reference_no,
                    'order_date' => $order->order_date?->format('d/m/Y'),
                    'expected_delivery_date' => $order->expected_delivery_date?->format('d/m/Y'),
                    'sales_quotation' => $order->salesQuotation?->reference_no,
                    'client' => UserCellData::of($order->client),
                    'warehouse' => $order->warehouse?->name,
                    'currency' => $order->currency?->code,
                    'total_amount' => number_format((float) $order->total_amount, 2),
                    'total_amount_full' => Number::money($order->total_amount),
                    'status' => StatusBadge::of($order->status),
                    'notes' => $order->notes,
                    'deleted' => $order->trashed(),
                    'editable' => ! $order->trashed() && $order->canBeEdited(),
                    'can_delete' => ! $order->trashed() && $order->canBeDeleted(),
                ]),
            'statusOptions' => SelectOptions::from(SalesOrderStatus::options()),
            'currencyOptions' => SelectOptions::currencies(),
            'warehouseOptions' => $this->documentOptions()['warehouseOptions'],
            'userOptions' => $this->userOptions(),
        ]);
    }

    /**
     * طلبية جديدة، ومن عرض سعر حين يصل رقمه (?sales_quotation_id=).
     */
    public function add(Request $request): Response
    {
        $currencyId = $this->baseCurrencyId();
        $today = now()->toDateString();
        $order = [
            'sales_quotation_id' => null,
            'client_id' => null,
            'branch_id' => null,
            'warehouse_id' => null,
            'fulfillment_type' => SalesOrderFulfillmentType::WAREHOUSE->value,
            'direct_supplier_id' => null,
            'order_date' => $today,
            'expected_delivery_date' => null,
            'currency_id' => $currencyId,
            'exchange_rate' => $this->exchangeRate($currencyId, $today) ?? '1',
            'tax_id' => null,
            'order_discount' => '0',
            'notes' => null,
            'items' => [],
            'services' => [],
            'customer_materials' => [],
        ];

        $quotation = $request->integer('sales_quotation_id')
            ? SalesQuotation::query()->with('client')->find($request->integer('sales_quotation_id'))
            : null;

        if ($quotation) {
            $order = array_replace($order, $this->service->quotationDefaults($quotation, $today));
        }

        return Inertia::render('Accounting/SalesOrder/Add', [
            'order' => $order,
            'referenceNo' => app(ReferenceNumberService::class)->preview('sales_order', now(), ['prefix' => 'SORD']),
            'statusLabel' => SalesOrderStatus::DRAFT->getLabel(),
            'client' => $quotation?->client ? ['value' => $quotation->client->id, 'label' => (string) $quotation->client->name] : null,
            'quotation' => $quotation ? ['value' => $quotation->id, 'label' => (string) $quotation->reference_no] : null,
            ...$this->formOptions(),
        ]);
    }

    public function store(SalesOrderRequest $request): RedirectResponse
    {
        try {
            $order = $this->service->saveForm(null, $request->formData());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.sales-orders.edit', $order->id)->with('success', __('Sales order created successfully'));
    }

    /**
     * المورد المقترح للتوريد المباشر (RemoteSelect): الموردون فقط.
     */
    public function suppliers(Request $request): JsonResponse
    {
        return response()->json(PartySelect::lookup('supplier', $request->string('q')->toString()));
    }

    /**
     * عروض السعر التي تتحول الى طلبية للزبون المختار (RemoteSelect).
     */
    public function quotations(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->service->quotationOptions($data['client_id'] ?? null, $data['q'] ?? null));
    }

    /**
     * ما يأخذه النموذج من عرض السعر المختار: الرأس والاصناف والخدمات.
     */
    public function quotationDefaults(Request $request): JsonResponse
    {
        $data = $request->validate(['sales_quotation_id' => ['required', 'integer'], 'order_date' => ['nullable', 'date']]);
        $quotation = SalesQuotation::query()->with('client')->findOrFail($data['sales_quotation_id']);

        return response()->json([
            ...$this->service->quotationDefaults($quotation, $data['order_date'] ?? null),
            'client' => $quotation->client ? ['value' => $quotation->client->id, 'label' => (string) $quotation->client->name] : null,
        ]);
    }

    /**
     * صفحة الطلبية: نموذج تعديل ما دامت غير مفوترة ولا ملغاة، ثم عرض للقراءة. حولها: العرض
     * المصدر، التسليم من المخزن، التوريد المباشر وطلبيات الشراء، اوامر الانتاج والفاتورة.
     */
    public function edit(int $salesOrder): Response
    {
        $order = $this->orders->findForPage($salesOrder);
        $user = auth()->user();

        return Inertia::render('Accounting/SalesOrder/Edit', [
            'order' => [
                'sales_quotation_id' => $order->sales_quotation_id,
                'client_id' => $order->client_id,
                'branch_id' => $order->branch_id,
                'warehouse_id' => $order->warehouse_id,
                'fulfillment_type' => $order->fulfillment_type?->value ?? SalesOrderFulfillmentType::WAREHOUSE->value,
                'direct_supplier_id' => $order->direct_supplier_id,
                'order_date' => $order->order_date?->toDateString(),
                'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
                'currency_id' => $order->currency_id,
                'exchange_rate' => Number::clean($order->exchange_rate, 8),
                'tax_id' => $order->tax_id,
                'order_discount' => Number::money($order->order_discount),
                'notes' => $order->notes,
                'items' => ItemsGridState::rows(
                    $order->items->reject(fn ($item): bool => $item->isService())->values(),
                    ['sales_quotation_item_id'],
                    extraPiece: fn ($piece): array => ['image_url' => $piece->product?->image],
                ),
                'services' => SalesServiceLine::rows($order->items, 'sales_quotation_item_id'),
                'customer_materials' => SalesServiceLine::materialRows($order->customerMaterials),
            ],
            'record' => [
                'id' => $order->id,
                'reference_no' => $order->reference_no,
                'status' => StatusBadge::of($order->status),
                'client' => $order->client ? ['value' => $order->client->id, 'label' => (string) $order->client->name] : null,
                'direct_supplier' => $order->directSupplier ? ['value' => $order->directSupplier->id, 'label' => (string) $order->directSupplier->name] : null,
                'margin_hint' => CustomerProfitMargin::hint($order->client_id),
                'editable' => $order->canBeEdited(),
                'fulfillment_locked' => $order->hasDirectPurchaseOrder(),
                'can_confirm' => $order->status === SalesOrderStatus::DRAFT,
                'can_prepare' => in_array($order->status, [SalesOrderStatus::DRAFT, SalesOrderStatus::CONFIRMED], true),
                'can_invoice' => $order->canCreateInvoice(),
                'can_produce' => $order->status !== SalesOrderStatus::CANCELLED,
                'can_delete' => $order->canBeDeleted(),
                'sales_quotation' => $order->salesQuotation ? ['id' => $order->salesQuotation->id, 'reference_no' => $order->salesQuotation->reference_no] : null,
                'sales_invoices' => $order->salesInvoices->sortByDesc('id')->map(fn ($invoice) => [
                    'id' => $invoice->id,
                    'reference_no' => $invoice->reference_no,
                    'status' => StatusBadge::of($invoice->status),
                ])->values()->all(),
                'production_orders' => $user?->can('Production Order View List')
                    ? $order->productionOrders()->latest('id')->get(['id', 'reference_no', 'status', 'total_cost'])->map(fn ($production) => [
                        'id' => $production->id,
                        'reference_no' => $production->reference_no,
                        'status' => $production->status?->getLabel(),
                        'total_cost' => number_format((float) $production->total_cost, 2),
                    ])->all()
                    : [],
                'direct_supply' => $this->directSupply($order),
                'delivery' => $this->delivery($order),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(SalesOrderRequest $request, int $salesOrder): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->saveForm($this->find($salesOrder), $request->formData()), __('Sales order updated successfully'));
    }

    public function confirm(int $salesOrder): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->service->saveThen($this->find($salesOrder), $this->formDataIfSent(), fn (SalesOrder $order) => $this->service->confirm($order)),
            __('Sales order confirmed successfully'),
        );
    }

    public function prepare(int $salesOrder): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->service->saveThen($this->find($salesOrder), $this->formDataIfSent(), fn (SalesOrder $order) => $this->service->sendToPreparation($order)),
            __('Sales order sent to preparation successfully'),
        );
    }

    /**
     * فاتورة بيع من الطلبية بعد حفظ تعديلاتها، ثم صفحة الفاتورة.
     */
    public function invoice(int $salesOrder): RedirectResponse
    {
        try {
            $invoice = $this->service->saveThen(
                $this->find($salesOrder),
                $this->formDataIfSent(),
                fn (SalesOrder $order) => app(SalesInvoiceService::class)->createFromOrder($order),
            );
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.sales-invoices.edit', ['salesInvoice' => $invoice->id])->with('success', __('Sales invoice created successfully'));
    }

    public function cancel(int $salesOrder): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->cancel($this->find($salesOrder)), __('Sales order cancelled successfully'));
    }

    /**
     * من القائمة او من صفحة الطلبية: بعد الحذف تعود القائمة.
     */
    public function destroy(int $salesOrder): RedirectResponse
    {
        try {
            $this->service->delete($this->find($salesOrder));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.sales-orders.index')->with('success', __('Sales order deleted successfully'));
    }

    public function info(int $salesOrder): JsonResponse
    {
        return response()->json(RecordInfo::of($this->find($salesOrder)));
    }

    public function pdf(int $salesOrder, AccountingPdfService $pdf): mixed
    {
        return $pdf->salesOrder($this->find($salesOrder));
    }

    private function find(int $id): SalesOrder
    {
        /** @var SalesOrder */
        return $this->orders->findOrFail($id);
    }

    /**
     * نموذج الطلبية حين يُرسل مع الاجراء (من صفحتها) ولصاحب صلاحية التعديل؛ من غيرها null.
     *
     * @return array<string, mixed>|null
     */
    private function formDataIfSent(): ?array
    {
        if (! request()->has('client_id') || ! auth()->user()?->can('Sales Order Update')) {
            return null;
        }

        return app(SalesOrderRequest::class)->formData();
    }

    /**
     * التوريد المباشر من مورد للزبون: المورد المقترح، ارسالها للمشتريات، وطلبيات الشراء لها.
     *
     * @return array<string, mixed>|null
     */
    private function directSupply(SalesOrder $order): ?array
    {
        if (! $order->isDirectSupply()) {
            return null;
        }

        $purchaseOrders = $order->directPurchaseOrders()->with('client')->latest('id')->get(['id', 'reference_no', 'status', 'client_id']);

        return [
            'supplier' => $order->directSupplier?->name,
            'is_draft' => $order->status === SalesOrderStatus::DRAFT,
            'notified_at' => $order->purchasing_notified_at?->format('d/m/Y H:i'),
            'awaiting' => $purchaseOrders->isEmpty() && ! in_array($order->status, [SalesOrderStatus::DRAFT, SalesOrderStatus::CANCELLED], true),
            'can_create_purchase_order' => $order->awaitsPurchaseOrder(),
            'purchase_orders' => $purchaseOrders->map(fn ($purchaseOrder) => [
                'id' => $purchaseOrder->id,
                'reference_no' => $purchaseOrder->reference_no,
                'supplier' => $purchaseOrder->client?->name,
                'status' => $purchaseOrder->status?->getLabel(),
            ])->all(),
        ];
    }

    /**
     * التسليم من المخزن: نسبة ما سُلِّم (سندات مرحّلة)، سنداته، وهل بقي ما يُسلَّم. طلبية الشحن
     * المباشر من المورد لا تخرج من المخزن.
     *
     * @return array<string, mixed>
     */
    private function delivery(SalesOrder $order): array
    {
        $service = app(DeliveryNoteService::class);
        $deliverable = in_array($order->status, DeliveryNoteService::DELIVERABLE_STATUSES, true);
        $isDirect = $deliverable && $service->isDirectShipment($order);
        $lines = $deliverable && ! $isDirect ? $service->outstandingLines($order, null, true) : collect();
        $ordered = (float) $lines->sum('ordered');
        $percent = $ordered > 0 ? (int) round(100 * (float) $lines->sum('delivered') / $ordered) : 0;

        return [
            'can_deliver' => $deliverable && ! $isDirect && $percent < 100,
            'is_direct' => $isDirect,
            'percent' => $percent,
            'notes' => DeliveryNote::query()->where('sales_order_id', $order->id)->latest('id')->get(['id', 'reference_no', 'status'])
                ->map(fn (DeliveryNote $note) => ['id' => $note->id, 'reference_no' => $note->reference_no, 'status' => $note->status?->getLabel()])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            ...$this->documentOptions(),
            'fulfillmentOptions' => SelectOptions::from(SalesOrderFulfillmentType::options()),
            'serviceOptions' => SalesServiceLine::options(),
        ];
    }
}
