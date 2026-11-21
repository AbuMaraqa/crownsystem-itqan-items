<?php

namespace Modules\Accounting\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\StockTransfer;
use Modules\Accounting\Entities\StockTransferItem;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Http\Controllers\Admin\Inventory\Concerns\InventoryDocumentPages;
use Modules\Accounting\Http\Requests\Admin\Inventory\StockTransferRequest;
use Modules\Accounting\Repositories\Contracts\StockTransferRepositoryInterface;
use Modules\Accounting\Services\StockTransferService;
use Modules\Accounting\Services\InventoryPdfService;
use Modules\Accounting\Support\InventoryLines;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\SelectOptions;
use Modules\Core\Support\AdminFilter;
use Modules\Core\Support\RecordInfo;

/**
 * التحويل المخزني بين مخزنين، او نقل داخل نفس المخزن الى موقع آخر حين يمتلئ موقع الصنف.
 * القواعد (الرصيد، الكلفة، القيد بين حسابي المخزنين) في StockTransferService.
 */
class StockTransferController extends Controller
{
    use InventoryDocumentPages;

    public function __construct(
        private readonly StockTransferRepositoryInterface $transfers,
        private readonly StockTransferService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/Inventory/StockTransfer/Index', [
            'table' => $this->documentTable(
                $this->transfers->tableQuery(),
                'transfer_date',
                ['notes'],
                [],
                ['from_warehouse_id', 'to_warehouse_id'],
                [AdminFilter::exact('from_warehouse', 'from_warehouse_id'), AdminFilter::exact('to_warehouse', 'to_warehouse_id')],
                [],
                fn (Model $transfer) => [
                    'from_warehouse' => $transfer->fromWarehouse?->name,
                    'to_warehouse' => $transfer->toWarehouse?->name,
                ],
            ),
            ...$this->tableOptions(),
        ]);
    }

    /**
     * تحويل جديد. ?warehouse= يبدأ به المرسل والمستلم (نقل داخل المخزن)، و?product= يضيف الصنف
     * (من بطاقة الصنف).
     */
    public function add(Request $request): Response
    {
        $warehouseId = $request->integer('warehouse') ?: null;
        $line = $request->integer('product') ? InventoryLines::productLine($request->integer('product')) : null;
        $lines = $line ? [[...$this->blankLine(), ...$line]] : [];

        return Inertia::render('Accounting/Inventory/StockTransfer/Add', [
            'transfer' => [
                'from_warehouse_id' => $warehouseId,
                'to_warehouse_id' => $warehouseId,
                'transfer_date' => now()->toDateString(),
                'notes' => null,
                'items' => $lines,
            ],
            ...$this->formOptions($warehouseId, $warehouseId, $lines),
        ]);
    }

    public function store(StockTransferRequest $request): RedirectResponse
    {
        $post = $request->boolean('post');

        if ($post) {
            Gate::authorize('Stock Transfer Post');
        }

        try {
            $transfer = $this->service->create($request->document());
        } catch (DomainException $exception) {
            return $this->refused($exception);
        }

        $edit = to_route('accounting.inventory.transfers.edit', $transfer->id);

        if (! $post) {
            return $edit->with('success', __('Saved successfully'));
        }

        try {
            $this->service->post($transfer);
        } catch (DomainException $exception) {
            return $this->refused($exception, $edit);
        }

        return $edit->with('success', __('Transfer posted.'));
    }

    public function edit(int $stockTransfer): Response
    {
        $transfer = $this->transfers->findForPage($stockTransfer);
        $lines = InventoryLines::byMainItem($transfer->items->map(fn (StockTransferItem $item) => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'barcode' => $item->barcode,
            'description' => $item->description,
            'quantity' => (float) $item->quantity,
            'from_location_id' => $item->from_location_id,
            'to_location_id' => $item->to_location_id,
            'from_code' => $item->fromLocation?->code,
            'to_code' => $item->toLocation?->code,
            'notes' => $item->notes,
            'total_cost' => Number::money($item->total_cost),
        ])->all());

        return Inertia::render('Accounting/Inventory/StockTransfer/Edit', [
            'transfer' => [
                'from_warehouse_id' => $transfer->from_warehouse_id,
                'to_warehouse_id' => $transfer->to_warehouse_id,
                'transfer_date' => $transfer->transfer_date?->toDateString(),
                'notes' => $transfer->notes,
                'items' => $lines,
            ],
            'record' => [
                ...$this->documentRecord($transfer),
                'date' => $transfer->transfer_date?->format('d/m/Y'),
                'from_warehouse' => $transfer->fromWarehouse?->name,
                'to_warehouse' => $transfer->toWarehouse?->name,
            ],
            ...$this->formOptions($transfer->canBeEdited() ? $transfer->from_warehouse_id : null, $transfer->to_warehouse_id, $lines),
        ]);
    }

    public function update(StockTransferRequest $request, int $stockTransfer): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->update($this->find($stockTransfer), $request->document()), __('Saved successfully'));
    }

    public function post(StockTransferRequest $request, int $stockTransfer): RedirectResponse
    {
        $transfer = $this->find($stockTransfer);

        return $this->attempt(function () use ($request, $transfer) {
            if ($transfer->canBeEdited()) {
                $transfer = $this->service->update($transfer, $request->document());
            }

            return $this->service->post($transfer);
        }, __('Transfer posted.'));
    }

    public function cancel(int $stockTransfer): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->cancel($this->find($stockTransfer)), __('Transfer cancelled and stock reversed.'));
    }

    public function destroy(int $stockTransfer): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->service->delete($this->find($stockTransfer)),
            __('Deleted successfully'),
            fn () => to_route('accounting.inventory.transfers.index'),
        );
    }

    public function pdf(int $stockTransfer, InventoryPdfService $pdf): mixed
    {
        return $pdf->stockTransfer($this->find($stockTransfer));
    }

    public function info(int $stockTransfer): JsonResponse
    {
        return response()->json(RecordInfo::of($this->find($stockTransfer)));
    }

    /**
     * عند تغيير مخزن: مواقعه، ورصيد الاصناف واماكنها فيه (للمخزن المرسل).
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
     * سطر الصنف المختار بكمية واحد، تحت صنفه الرئيسي ان كان صنفاً فرعياً.
     */
    public function line(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer']]);
        $line = InventoryLines::productLine($data['product_id']);

        return response()->json($line ? [...$this->blankLine(), ...$line] : null);
    }

    private function find(int $id): StockTransfer
    {
        /** @var StockTransfer */
        return $this->transfers->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function blankLine(): array
    {
        return ['id' => null, 'quantity' => 1, 'from_location_id' => null, 'to_location_id' => null, 'notes' => null];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function formOptions(?int $fromWarehouseId, ?int $toWarehouseId, array $lines): array
    {
        return [
            'warehouseOptions' => SelectOptions::from(Warehouse::options()),
            'fromContext' => InventoryLines::warehouseContext($fromWarehouseId, array_column($lines, 'product_id')),
            'toContext' => InventoryLines::warehouseContext($toWarehouseId, []),
            'canSeeCost' => (bool) auth()->user()?->can('Stock Value View'),
        ];
    }
}
