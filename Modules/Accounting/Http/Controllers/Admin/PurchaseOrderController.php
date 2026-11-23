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
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Enums\PurchaseOrderDeliveryType;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Http\Controllers\Admin\Concerns\DeletesManyDocuments;
use Modules\Accounting\Http\Controllers\Admin\Concerns\PurchaseDocumentPage;
use Modules\Accounting\Http\Requests\Admin\PurchaseOrderRequest;
use Modules\Accounting\Repositories\Contracts\PurchaseOrderRepositoryInterface;
use Modules\Accounting\Services\AccountingPdfService;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Services\PurchaseOrderService;
use Modules\Accounting\Support\DirectPurchaseOrderDraft;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\PartySelect;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Branch\Enums\BranchStatus;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\UserCellData;
use Modules\Core\Support\RecordInfo;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * طلبيات الشراء: ما يُطلب من المورد بكمياته واوزانه وسعر اختياري، للمخزن (تُستلم بسند استلام)
 * او شحن مباشر للزبون. الاجراءات: تأكيد، عرض سعر، استلام، تسليم للزبون، فاتورة، الغاء. البنود
 * في جدول الاصناف الموحد، والقواعد كلها في PurchaseOrderService.
 */
class PurchaseOrderController extends Controller
{
    use DeletesManyDocuments;
    use PurchaseDocumentPage;

    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $orders,
        private readonly PurchaseOrderService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/PurchaseOrder/Index', [
            'table' => AdminTable::for($this->orders->tableQuery())
                ->search(['reference_no', 'notes'], null)
                ->filters(
                    AdminFilter::text('reference_no'),
                    $this->partyFilter(),
                    AdminFilter::exact('warehouse', 'warehouse_id'),
                    AdminFilter::text('notes'),
                    AdminFilter::exact('status'),
                    AdminFilter::exact('delivery_type'),
                    AdminFilter::exact('created_by'),
                    $this->itemsFilter('barcode'),
                    $this->itemsFilter('description'),
                    AllowedFilter::trashed(),
                    ...AdminFilter::dateRange('order_date'),
                    ...AdminFilter::dateRange('expected_delivery_date'),
                    ...AdminFilter::numberRange('total_amount'),
                )
                ->sorts('reference_no', 'order_date', 'expected_delivery_date', 'total_amount', 'status', 'id')
                ->defaultSort('-order_date')
                ->withCreator()
                ->rows(fn (PurchaseOrder $order) => [
                    'id' => $order->id,
                    'reference_no' => $order->reference_no,
                    'order_date' => $order->order_date?->format('d/m/Y'),
                    'expected_delivery_date' => $order->expected_delivery_date?->format('d/m/Y'),
                    'client' => UserCellData::of($order->client),
                    'warehouse' => $order->warehouse?->name,
                    'delivery_type' => PurchaseOrderDeliveryType::tryFrom((string) $order->delivery_type)?->getLabel(),
                    'items_count' => $order->items_count,
                    'total_amount' => number_format((float) $order->total_amount, 2),
                    'status' => StatusBadge::of($order->status),
                    'notes' => $order->notes,
                    'deleted' => $order->trashed(),
                    'editable' => ! $order->trashed() && $order->canBeEdited(),
                    'can_delete' => ! $order->trashed() && $order->canBeDeleted(),
                ]),
            'statusOptions' => SelectOptions::from(PurchaseOrderStatus::options()),
            'deliveryTypeOptions' => SelectOptions::from(PurchaseOrderDeliveryType::options()),
            'warehouseOptions' => $this->documentOptions()['warehouseOptions'],
            'userOptions' => $this->userOptions(),
        ]);
    }

    /**
     * «انشاء طلبية شراء» من اشعار طلبية بيع توريد مباشر (sales_order_id) يفتح الصفحة معبأة منها.
     */
    public function add(Request $request): Response
    {
        $order = [
            'client_id' => null,
            'branch_id' => null,
            'warehouse_id' => null,
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => null,
            'delivery_type' => PurchaseOrderDeliveryType::WAREHOUSE->value,
            'direct_customer_id' => null,
            'direct_sales_order_id' => null,
            'notes' => null,
            'items' => [],
        ];
        $salesOrder = DirectPurchaseOrderDraft::find($request->input('sales_order_id'));

        if ($salesOrder) {
            $order = array_replace($order, DirectPurchaseOrderDraft::state($salesOrder));
        }

        return Inertia::render('Accounting/PurchaseOrder/Add', [
            'order' => $order,
            'supplier' => $this->option($salesOrder?->directSupplier ?? null),
            'customer' => $this->option($salesOrder?->client),
            'referenceNo' => app(ReferenceNumberService::class)->preview('purchase_order', now(), ['prefix' => 'PORD']),
            'statusLabel' => PurchaseOrderStatus::DRAFT->getLabel(),
            'branchClosed' => $request->user()?->branch?->status === BranchStatus::CLOSED,
            ...$this->formOptions($order['direct_customer_id']),
        ]);
    }

    public function store(PurchaseOrderRequest $request): RedirectResponse
    {
        try {
            $order = $this->service->save(null, $request->payload(), (array) $request->file('attachments', []));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.purchase-orders.edit', $order->id)->with('success', __('Purchase order created successfully'));
    }

    /**
     * صفحة الطلبية: نموذج تعديل ما دامت قابلة للتعديل ولدى المستخدم صلاحية التعديل، والا عرض
     * للقراءة (الطلبية المفوترة تُفتح من رقمها للاطلاع فقط).
     */
    public function edit(Request $request, int $purchaseOrder): Response
    {
        $order = $this->orders->findForPage($purchaseOrder);
        $quotation = $order->activePurchaseQuotation();
        $receiving = $this->service->receiving($order);
        $hasReceipts = $order->goodsReceipts()->exists();

        return Inertia::render('Accounting/PurchaseOrder/Edit', [
            'order' => [
                'client_id' => $order->client_id,
                'branch_id' => $order->branch_id,
                'warehouse_id' => $order->warehouse_id,
                'order_date' => $order->order_date?->toDateString(),
                'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
                'delivery_type' => $order->delivery_type ?? PurchaseOrderDeliveryType::WAREHOUSE->value,
                'direct_customer_id' => $order->direct_customer_id,
                'direct_sales_order_id' => $order->direct_sales_order_id,
                'notes' => $order->notes,
                'items' => $this->service->gridRows($order),
            ],
            'supplier' => $this->option($order->client),
            'customer' => $this->option($order->directCustomer),
            'attachments' => $order->getMedia('attachments')->map(fn ($media) => ['id' => $media->id, 'url' => $media->getUrl()])->values()->all(),
            'record' => [
                'id' => $order->id,
                'reference_no' => $order->reference_no,
                'status' => StatusBadge::of($order->status),
                'supplier' => $order->client?->name,
                'order_date' => $order->order_date?->format('d/m/Y'),
                'items_count' => $order->items->count(),
                'total_amount' => Number::money($order->total_amount),
                'editable' => $order->canBeEdited() && $request->user()->can('Purchase Order Update'),
                'locked' => ! $order->canBeEdited(),
                'delivery_locked' => $hasReceipts,
                'can_confirm' => $this->service->canConfirm($order),
                'can_create_quotation' => $this->service->canCreateQuotation($order),
                'can_receive_goods' => $this->service->canCreateGoodsReceipt($order),
                'can_mark_received' => $this->service->canMarkReceived($order),
                'can_invoice' => $order->canCreateInvoice(),
                'can_cancel' => $order->canBeEdited(),
                'can_delete' => $order->canBeDeleted(),
                'quotation' => $quotation ? ['id' => $quotation->id, 'reference_no' => $quotation->reference_no, 'status' => StatusBadge::of($quotation->status)] : null,
                'direct' => $order->isDirectShipment() ? [
                    'customer' => $order->directCustomer?->name,
                    'sales_order' => $order->directSalesOrder ? ['id' => $order->directSalesOrder->id, 'reference_no' => $order->directSalesOrder->reference_no] : null,
                ] : null,
                'receiving' => $receiving === [] ? null : [
                    'percent' => $receiving['percent'],
                    'receipts' => $receiving['receipts']->map(fn (GoodsReceipt $receipt) => [
                        'id' => $receipt->id,
                        'reference_no' => $receipt->reference_no,
                        'status' => $receipt->status?->getLabel(),
                    ])->values()->all(),
                ],
            ],
            ...$this->formOptions($order->direct_customer_id),
        ]);
    }

    public function update(PurchaseOrderRequest $request, int $purchaseOrder): RedirectResponse
    {
        return $this->attempt(fn () => $this->saveFrom($request, $this->find($purchaseOrder)), __('Purchase order updated successfully'));
    }

    public function confirm(int $purchaseOrder): RedirectResponse
    {
        return $this->transition($purchaseOrder, fn (PurchaseOrder $order) => $this->service->confirm($order), __('Purchase order confirmed successfully'));
    }

    /**
     * «تم التسليم للزبون» لطلبية الشحن المباشر.
     */
    public function receive(int $purchaseOrder): RedirectResponse
    {
        return $this->transition($purchaseOrder, fn (PurchaseOrder $order) => $this->service->markReceived($order), __('Purchase order marked as received successfully'));
    }

    public function cancel(int $purchaseOrder): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->cancel($this->find($purchaseOrder)), __('Purchase order cancelled successfully'));
    }

    /**
     * عرض سعر الشراء لهذه الطلبية: يُفتح ان وُجد والا يُنشأ، بعد حفظ تعديلاتها.
     */
    public function quotation(int $purchaseOrder): RedirectResponse
    {
        try {
            $order = $this->saveBeforeAction($this->find($purchaseOrder));
            $quotation = $this->service->openQuotation($order);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.purchase-quotations.edit', $quotation->id);
    }

    public function invoice(int $purchaseOrder, PurchaseInvoiceService $invoices): RedirectResponse
    {
        try {
            $order = $this->saveBeforeAction($this->find($purchaseOrder));
            $invoice = $invoices->createFromOrder($order);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.purchase-invoices.edit', $invoice->id)->with('success', __('Purchase invoice created successfully'));
    }

    public function destroy(int $purchaseOrder): RedirectResponse
    {
        try {
            $this->service->delete($this->find($purchaseOrder));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.purchase-orders.index')->with('success', __('Purchase order deleted successfully'));
    }

    /**
     * The list's rows, deleted ones included (its «deleted» filter shows them; they are refused).
     */
    protected function bulkDeleteQuery(): Builder
    {
        return $this->orders->tableQuery()->withTrashed();
    }

    /**
     * @param  PurchaseOrder  $document
     */
    protected function deleteDocument(Model $document): void
    {
        $this->service->delete($document);
    }

    public function info(int $purchaseOrder): JsonResponse
    {
        return response()->json(RecordInfo::of($this->orders->query()->withTrashed()->findOrFail($purchaseOrder)));
    }

    public function print(int $purchaseOrder, AccountingPdfService $pdf): mixed
    {
        return $pdf->purchaseOrder($this->find($purchaseOrder));
    }

    /**
     * زبائن الشحن المباشر (حقل «شحن الى الزبون»).
     */
    public function customers(Request $request): JsonResponse
    {
        return response()->json(PartySelect::lookup('customer', $request->string('q')->toString()));
    }

    /**
     * طلبيات البيع التي يُشحن لها مباشرة: طلبيات الزبون المختار غير المسودة والملغاة.
     */
    public function salesOrders(Request $request): JsonResponse
    {
        $data = $request->validate(['customer_id' => ['nullable', 'integer']]);

        return response()->json($this->orders->directSalesOrderOptions(isset($data['customer_id']) ? (int) $data['customer_id'] : null));
    }

    private function find(int $id): PurchaseOrder
    {
        /** @var PurchaseOrder */
        return $this->orders->findOrFail($id);
    }

    private function saveFrom(PurchaseOrderRequest $request, ?PurchaseOrder $order): PurchaseOrder
    {
        return $this->service->save(
            $order,
            $request->payload(),
            (array) $request->file('attachments', []),
            (array) ($request->validated()['removed_attachments'] ?? []),
            (array) ($request->validated()['attachment_order'] ?? []),
        );
    }

    /**
     * اجراء على الطلبية من صفحتها: يحفظ ما أُدخل اولاً (ان كانت قابلة للتعديل ولدى المستخدم صلاحية
     * التعديل)، بنفس فحوص الحفظ العادي.
     */
    private function saveBeforeAction(PurchaseOrder $order): PurchaseOrder
    {
        if (! request()->has('client_id') || ! $order->canBeEdited() || ! request()->user()->can('Purchase Order Update')) {
            return $order;
        }

        return $this->saveFrom(app(PurchaseOrderRequest::class), $order);
    }

    /**
     * @param  callable(PurchaseOrder): mixed  $action
     */
    private function transition(int $id, callable $action, string $message): RedirectResponse
    {
        return $this->attempt(function () use ($id, $action): void {
            $action($this->saveBeforeAction($this->find($id)));
        }, $message);
    }

    /**
     * @return array{value: int, label: string}|null
     */
    private function option(mixed $user): ?array
    {
        return $user ? ['value' => (int) $user->id, 'label' => (string) $user->name] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(?int $customerId): array
    {
        return [
            'warehouseOptions' => $this->documentOptions()['warehouseOptions'],
            'deliveryTypeOptions' => SelectOptions::from(PurchaseOrderDeliveryType::options()),
            'salesOrderOptions' => $this->orders->directSalesOrderOptions($customerId),
        ];
    }
}
