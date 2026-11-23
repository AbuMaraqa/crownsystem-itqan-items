<?php

namespace Modules\Accounting\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Http\Controllers\Admin\Concerns\DeletesManyDocuments;
use Modules\Accounting\Http\Controllers\Admin\Inventory\Concerns\InventoryDocumentPages;
use Modules\Accounting\Http\Requests\Admin\Inventory\GoodsReceiptRequest;
use Modules\Accounting\Repositories\Contracts\GoodsReceiptRepositoryInterface;
use Modules\Accounting\Services\GoodsReceiptService;
use Modules\Accounting\Services\InventoryPdfService;
use Modules\Accounting\Support\InventoryLines;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\SelectOptions;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\UserCellData;
use Modules\Core\Support\RecordInfo;

/**
 * سند الاستلام: يُختار طلب الشراء فتظهر سطوره المتبقية (الصنف الموزون بقطعه)، ويُدخل امين
 * المخزن الكمية المستلمة وموقعها، ثم يُرحّل فتدخل البضاعة المخزون بقيدها وطبقات كلفتها.
 * القواعد كلها في GoodsReceiptService؛ هنا قراءة الطلب وشكل الصفحة.
 */
class GoodsReceiptController extends Controller
{
    use DeletesManyDocuments;
    use InventoryDocumentPages;

    public function __construct(
        private readonly GoodsReceiptRepositoryInterface $receipts,
        private readonly GoodsReceiptService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/Inventory/GoodsReceipt/Index', [
            'table' => $this->documentTable(
                $this->receipts->tableQuery(),
                'receipt_date',
                ['container_no', 'supplier_document_no'],
                ['purchaseOrder' => 'reference_no', 'client' => 'name'],
                'warehouse_id',
                [AdminFilter::text('container_no'), $this->relationText('purchase_order', 'purchaseOrder', 'reference_no'), $this->relationText('party', 'client', 'name')],
                [],
                fn (Model $receipt) => [
                    'purchase_order' => $receipt->purchaseOrder?->reference_no,
                    'party' => UserCellData::of($receipt->client),
                    'warehouse' => $receipt->warehouse?->name,
                    'container_no' => $receipt->container_no,
                ],
            ),
            ...$this->tableOptions(),
        ]);
    }

    /**
     * سند جديد. ?purchase_order= يفتحه على طلبية (من صفحة الطلبية او من اختيارها هنا).
     */
    public function add(Request $request): Response
    {
        $order = $request->integer('purchase_order') ? PurchaseOrder::query()->with('client')->find($request->integer('purchase_order')) : null;
        $warehouseId = $order?->warehouse_id;
        $lines = $order ? $this->service->formLines($order, null, $warehouseId) : [];

        return Inertia::render('Accounting/Inventory/GoodsReceipt/Add', [
            'receipt' => [
                'purchase_order_id' => $order?->id,
                'warehouse_id' => $warehouseId,
                'receipt_date' => now()->toDateString(),
                'supplier_document_no' => null,
                'container_no' => null,
                'received_by_name' => auth()->user()?->name,
                'notes' => null,
                'items' => InventoryLines::byLabel($lines, 'group', 'purchase_order_item_id'),
            ],
            'order' => $this->orderSummary($order),
            'value' => $order ? $this->value($order, $lines, null) : null,
            ...$this->formOptions($warehouseId, $lines),
        ]);
    }

    /**
     * «حفظ كمسودة»، او «ترحيل واستلام» (post=1) لمن يملك صلاحية الترحيل: يُحفظ ثم يُرحّل،
     * ورفض الترحيل يفتح المسودة المحفوظة مع سببه.
     */
    public function store(GoodsReceiptRequest $request): RedirectResponse
    {
        $post = $request->boolean('post');

        if ($post) {
            Gate::authorize('Goods Receipt Post');
        }

        try {
            $receipt = $this->service->create($request->document());
        } catch (DomainException $exception) {
            return $this->refused($exception);
        }

        $edit = to_route('accounting.inventory.goods-receipts.edit', $receipt->id);

        if (! $post) {
            return $edit->with('success', __('Saved successfully'));
        }

        try {
            $this->service->post($receipt);
        } catch (DomainException $exception) {
            return $this->refused($exception, $edit);
        }

        return $edit->with('success', __('Goods receipt posted. The items are now in stock.'));
    }

    /**
     * صفحة السند: نموذج ما دام مسودة، ثم عرض للقراءة.
     */
    public function edit(int $goodsReceipt): Response
    {
        $receipt = $this->receipts->findForPage($goodsReceipt);
        $order = $receipt->purchaseOrder;
        $lines = $order ? $this->service->formLines($order, $receipt) : [];

        return Inertia::render('Accounting/Inventory/GoodsReceipt/Edit', [
            'receipt' => [
                'purchase_order_id' => $receipt->purchase_order_id,
                'warehouse_id' => $receipt->warehouse_id,
                'receipt_date' => $receipt->receipt_date?->toDateString(),
                'supplier_document_no' => $receipt->supplier_document_no,
                'container_no' => $receipt->container_no,
                'received_by_name' => $receipt->received_by_name,
                'notes' => $receipt->notes,
                'items' => InventoryLines::byLabel($lines, 'group', 'purchase_order_item_id'),
            ],
            'record' => [
                ...$this->documentRecord($receipt),
                'date' => $receipt->receipt_date?->format('d/m/Y'),
            ],
            'order' => $this->orderSummary($order),
            'value' => $order && $receipt->canBeEdited() ? $this->value($order, $lines, $receipt->id) : null,
            ...$this->formOptions($receipt->warehouse_id, $lines),
        ]);
    }

    public function update(GoodsReceiptRequest $request, int $goodsReceipt): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->update($this->find($goodsReceipt), $request->document()), __('Saved successfully'));
    }

    /**
     * يحفظ المسودة كما في الشاشة ثم يرحّلها: تدخل الكميات المخزن بقيدها.
     */
    public function post(GoodsReceiptRequest $request, int $goodsReceipt): RedirectResponse
    {
        $receipt = $this->find($goodsReceipt);

        return $this->attempt(function () use ($request, $receipt) {
            if ($receipt->canBeEdited()) {
                $receipt = $this->service->update($receipt, $request->document());
            }

            return $this->service->post($receipt);
        }, __('Goods receipt posted. The items are now in stock.'));
    }

    public function cancel(int $goodsReceipt): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->cancel($this->find($goodsReceipt)), __('Goods receipt cancelled and stock reversed.'));
    }

    public function destroy(int $goodsReceipt): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->service->delete($this->find($goodsReceipt)),
            __('Deleted successfully'),
            fn () => to_route('accounting.inventory.goods-receipts.index'),
        );
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->receipts->tableQuery();
    }

    /**
     * @param  GoodsReceipt  $document
     */
    protected function deleteDocument(Model $document): void
    {
        $this->service->delete($document);
    }

    public function pdf(int $goodsReceipt, InventoryPdfService $pdf): mixed
    {
        return $pdf->goodsReceipt($this->find($goodsReceipt));
    }

    public function info(int $goodsReceipt): JsonResponse
    {
        return response()->json(RecordInfo::of($this->find($goodsReceipt)));
    }

    /**
     * طلبيات الشراء المؤكدة للمخزن التي يمكن الاستلام منها، بالرقم او المورد.
     */
    public function orders(Request $request): JsonResponse
    {
        return response()->json($this->receipts->receivableOrders($request->string('q')->toString()));
    }

    /**
     * عند تغيير المخزن: مواقعه، والموقع الاساسي لكل صنف (يأخذه كل سطر من جديد).
     */
    public function warehouse(Request $request): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer'], 'product_ids' => ['array', 'max:2000'], 'product_ids.*' => ['integer']]);

        return response()->json(InventoryLines::warehouseContext($data['warehouse_id'] ?? null, $data['product_ids'] ?? []));
    }

    /**
     * بحث الصنف بالاسم او الباركود: قارئ الباركود يزيد كمية سطره واحداً.
     */
    public function products(Request $request): JsonResponse
    {
        return response()->json(InventoryLines::search($request->string('q')->toString()));
    }

    /**
     * قيمة السند للكميات المكتوبة في الشاشة (قطع الوزن بنصيبها من قيمة سطرها).
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purchase_order_id' => ['required', 'integer'],
            'receipt_id' => ['nullable', 'integer'],
            'items' => ['array', 'max:2000'],
            'items.*' => ['array'],
        ]);
        $order = PurchaseOrder::query()->findOrFail($data['purchase_order_id']);

        return response()->json(['value' => $this->value($order, $data['items'] ?? [], $data['receipt_id'] ?? null)]);
    }

    private function find(int $id): GoodsReceipt
    {
        /** @var GoodsReceipt */
        return $this->receipts->findOrFail($id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function value(PurchaseOrder $order, array $lines, ?int $receiptId): ?string
    {
        return auth()->user()?->can('Stock Value View') ? Number::money($this->service->formValue($order, $lines, $receiptId)) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function orderSummary(?PurchaseOrder $order): ?array
    {
        if (! $order) {
            return null;
        }

        $order->loadMissing('client');

        return [
            'id' => $order->id,
            'reference_no' => $order->reference_no,
            'party' => $order->client?->name,
            'date' => $order->order_date?->format('d/m/Y'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function formOptions(?int $warehouseId, array $lines): array
    {
        return [
            'warehouseOptions' => SelectOptions::from(Warehouse::options()),
            'context' => InventoryLines::warehouseContext($warehouseId, array_column($lines, 'product_id')),
            'canSeeCost' => (bool) auth()->user()?->can('Stock Value View'),
        ];
    }
}
