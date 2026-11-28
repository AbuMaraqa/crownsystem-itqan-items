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
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Http\Controllers\Admin\Concerns\DeletesManyDocuments;
use Modules\Accounting\Http\Controllers\Admin\Inventory\Concerns\InventoryDocumentPages;
use Modules\Accounting\Http\Requests\Admin\Inventory\DeliveryNoteRequest;
use Modules\Accounting\Repositories\Contracts\DeliveryNoteRepositoryInterface;
use Modules\Accounting\Services\DeliveryNoteService;
use Modules\Accounting\Services\InventoryPdfService;
use Modules\Accounting\Support\InventoryLines;
use Modules\Accounting\Support\SelectOptions;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\RecordInfo;
use Modules\Core\Support\UserCellData;

/**
 * سند التسليم: يُختار طلب البيع فتظهر سطوره المتبقية مع الرصيد المتاح ومكان الصنف، ويُطبع
 * كقائمة تحضير للعامل، ثم يُرحّل فتخرج البضاعة من المخزون بقيد كلفة المبيعات. القواعد كلها
 * في DeliveryNoteService (ومنها منع تجاوز الرصيد الا لصاحب «Stock Allow Negative»).
 */
class DeliveryNoteController extends Controller
{
    use DeletesManyDocuments;
    use InventoryDocumentPages;

    public function __construct(
        private readonly DeliveryNoteRepositoryInterface $notes,
        private readonly DeliveryNoteService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/Inventory/DeliveryNote/Index', [
            'table' => $this->documentTable(
                $this->notes->tableQuery(),
                'delivery_date',
                ['salesOrder' => 'reference_no', 'client' => 'name'],
                'warehouse_id',
                [AdminFilter::text('driver_name'), $this->relationText('sales_order', 'salesOrder', 'reference_no'), $this->relationText('party', 'client', 'name')],
                [],
                fn (Model $note) => [
                    'sales_order' => $note->salesOrder?->reference_no,
                    'party' => UserCellData::of($note->client),
                    'warehouse' => $note->warehouse?->name,
                    'driver_name' => $note->driver_name,
                ],
            ),
            ...$this->tableOptions(),
        ]);
    }

    /**
     * سند جديد. ?sales_order= يفتحه على طلبية؛ طلبية يشحنها المورد مباشرة لا تخرج من المخزن.
     */
    public function add(Request $request): Response|RedirectResponse
    {
        $order = $request->integer('sales_order') ? SalesOrder::query()->with('client')->find($request->integer('sales_order')) : null;

        if ($order && $this->service->isDirectShipment($order)) {
            return $this->refused(
                new DomainException(__('This sales order is shipped directly by the supplier and does not leave the warehouse.')),
                to_route('accounting.inventory.delivery-notes.add'),
            );
        }

        $warehouseId = $order?->warehouse_id;
        $lines = $order ? $this->service->formLines($order) : [];

        return Inertia::render('Accounting/Inventory/DeliveryNote/Add', [
            'note' => [
                'sales_order_id' => $order?->id,
                'warehouse_id' => $warehouseId,
                'delivery_date' => now()->toDateString(),
                'driver_name' => null,
                'vehicle_no' => null,
                'receiver_name' => null,
                'delivery_address' => null,
                'notes' => null,
                'items' => InventoryLines::byLabel($lines, 'group', 'sales_order_item_id'),
            ],
            'order' => $this->orderSummary($order),
            ...$this->formOptions($warehouseId, $lines),
        ]);
    }

    /**
     * «حفظ كمسودة»، او «ترحيل وتسليم» (post=1) لمن يملك صلاحية الترحيل.
     */
    public function store(DeliveryNoteRequest $request): RedirectResponse
    {
        $post = $request->boolean('post');

        if ($post) {
            Gate::authorize('Delivery Note Post');
        }

        try {
            $note = $this->service->create($request->document());
        } catch (DomainException $exception) {
            return $this->refused($exception);
        }

        $edit = to_route('accounting.inventory.delivery-notes.edit', $note->id);

        if (! $post) {
            return $edit->with('success', __('Saved successfully'));
        }

        try {
            $this->service->post($note);
        } catch (DomainException $exception) {
            return $this->refused($exception, $edit);
        }

        return $edit->with('success', __('Delivery note posted. The items left the warehouse.'));
    }

    public function edit(int $deliveryNote): Response
    {
        $note = $this->notes->findForPage($deliveryNote);
        $order = $note->salesOrder;
        $lines = $order ? $this->service->formLines($order, $note) : [];

        return Inertia::render('Accounting/Inventory/DeliveryNote/Edit', [
            'note' => [
                'sales_order_id' => $note->sales_order_id,
                'warehouse_id' => $note->warehouse_id,
                'delivery_date' => $note->delivery_date?->toDateString(),
                'driver_name' => $note->driver_name,
                'vehicle_no' => $note->vehicle_no,
                'receiver_name' => $note->receiver_name,
                'delivery_address' => $note->delivery_address,
                'notes' => $note->notes,
                'items' => InventoryLines::byLabel($lines, 'group', 'sales_order_item_id'),
            ],
            'record' => [
                ...$this->documentRecord($note),
                'date' => $note->delivery_date?->format('d/m/Y'),
            ],
            'order' => $this->orderSummary($order),
            ...$this->formOptions($note->warehouse_id, $lines),
        ]);
    }

    public function update(DeliveryNoteRequest $request, int $deliveryNote): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->update($this->find($deliveryNote), $request->document()), __('Saved successfully'));
    }

    /**
     * يحفظ المسودة كما في الشاشة ثم يرحّلها: تخرج الكميات من المخزن بقيدها.
     */
    public function post(DeliveryNoteRequest $request, int $deliveryNote): RedirectResponse
    {
        $note = $this->find($deliveryNote);

        return $this->attempt(function () use ($request, $note) {
            if ($note->canBeEdited()) {
                $note = $this->service->update($note, $request->document());
            }

            return $this->service->post($note);
        }, __('Delivery note posted. The items left the warehouse.'));
    }

    public function cancel(int $deliveryNote): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->cancel($this->find($deliveryNote)), __('Delivery note cancelled and the items returned to stock.'));
    }

    public function destroy(int $deliveryNote): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->service->delete($this->find($deliveryNote)),
            __('Deleted successfully'),
            fn () => to_route('accounting.inventory.delivery-notes.index'),
        );
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->notes->tableQuery();
    }

    /**
     * @param  DeliveryNote  $document
     */
    protected function deleteDocument(Model $document): void
    {
        $this->service->delete($document);
    }

    public function pdf(int $deliveryNote, InventoryPdfService $pdf): mixed
    {
        return $pdf->deliveryNote($this->find($deliveryNote));
    }

    public function info(int $deliveryNote): JsonResponse
    {
        return response()->json(RecordInfo::of($this->find($deliveryNote)));
    }

    /**
     * طلبيات البيع القابلة للتسليم، بالرقم او الزبون.
     */
    public function orders(Request $request): JsonResponse
    {
        return response()->json($this->notes->deliverableOrders($request->string('q')->toString()));
    }

    /**
     * عند تغيير المخزن: مواقعه، رصيد كل صنف فيه، واماكن الصنف ورصيد كل مكان.
     */
    public function warehouse(Request $request): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer'], 'product_ids' => ['array', 'max:2000'], 'product_ids.*' => ['integer']]);

        return response()->json(InventoryLines::warehouseContext($data['warehouse_id'] ?? null, $data['product_ids'] ?? []));
    }

    public function products(Request $request): JsonResponse
    {
        return response()->json(InventoryLines::search($request->string('q')->toString()));
    }

    /**
     * «تعبئة من الرصيد المتوفر»: كمية كل سطر بما يغطيه رصيد المخزن.
     */
    public function available(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'items' => ['array', 'max:2000'],
            'items.*.key' => ['required', 'string'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.outstanding' => ['required', 'numeric'],
        ]);

        return response()->json((object) $this->service->fillFromAvailable($data['items'] ?? [], (int) $data['warehouse_id']));
    }

    private function find(int $id): DeliveryNote
    {
        /** @var DeliveryNote */
        return $this->notes->findOrFail($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function orderSummary(?SalesOrder $order): ?array
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
