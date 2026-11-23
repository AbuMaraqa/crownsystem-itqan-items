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
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\StockAdjustment;
use Modules\Accounting\Entities\StockAdjustmentItem;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\StockAdjustmentType;
use Modules\Accounting\Http\Controllers\Admin\Concerns\DeletesManyDocuments;
use Modules\Accounting\Http\Controllers\Admin\Inventory\Concerns\InventoryDocumentPages;
use Modules\Accounting\Http\Requests\Admin\Inventory\StockAdjustmentRequest;
use Modules\Accounting\Repositories\Contracts\StockAdjustmentRepositoryInterface;
use Modules\Accounting\Services\StockAdjustmentService;
use Modules\Accounting\Services\InventoryPdfService;
use Modules\Accounting\Support\InventoryLines;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Core\Support\AdminFilter;
use Modules\Items\Entities\Product;
use Modules\Items\Support\ProductSearch;
use Modules\Core\Support\RecordInfo;

/**
 * رصيد افتتاحي / جرد / تسوية. في الجرد يُحمَّل كشف الاصناف برصيد النظام ويُدخل العدد الفعلي،
 * ويظهر الفرق قبل الترحيل. القواعد (الفرق لحظة الترحيل، الكلفة، القيد) في StockAdjustmentService.
 */
class StockAdjustmentController extends Controller
{
    use DeletesManyDocuments;
    use InventoryDocumentPages;

    public function __construct(
        private readonly StockAdjustmentRepositoryInterface $adjustments,
        private readonly StockAdjustmentService $service,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/Inventory/StockAdjustment/Index', [
            'table' => $this->documentTable(
                $this->adjustments->tableQuery(),
                'adjustment_date',
                ['reason', 'notes'],
                [],
                'warehouse_id',
                [AdminFilter::exact('type'), AdminFilter::text('reason')],
                ['type'],
                fn (Model $adjustment) => [
                    'type' => StatusBadge::of($adjustment->type),
                    'warehouse' => $adjustment->warehouse?->name,
                    'reason' => $adjustment->reason,
                ],
            ),
            ...$this->tableOptions(),
            'typeOptions' => SelectOptions::from(StockAdjustmentType::options()),
        ]);
    }

    /**
     * مستند جديد: ?type= (جرد افتراضياً)، ?warehouse= (والا اول مخزن)، ?product= يضيف الصنف
     * (رصيد افتتاحي من تعريف الصنف).
     */
    public function add(Request $request): Response
    {
        $type = StockAdjustmentType::tryFrom((string) $request->query('type')) ?? StockAdjustmentType::COUNT;
        $warehouseId = $request->integer('warehouse') ?: ((int) array_key_first(Warehouse::options()) ?: null);
        $product = $request->integer('product') ? Product::query()->with('translations')->find($request->integer('product')) : null;
        $lines = $product && ! $product->isService() ? InventoryLines::byMainItem([$this->service->newLine($product, $type, $warehouseId)]) : [];

        return Inertia::render('Accounting/Inventory/StockAdjustment/Add', [
            'adjustment' => [
                'type' => $type->value,
                'warehouse_id' => $warehouseId,
                'adjustment_date' => now()->toDateString(),
                'reason' => null,
                'notes' => null,
                'items' => $lines,
            ],
            ...$this->formOptions($warehouseId, $lines),
        ]);
    }

    public function store(StockAdjustmentRequest $request): RedirectResponse
    {
        $post = $request->boolean('post');

        if ($post) {
            Gate::authorize('Stock Adjustment Post');
        }

        try {
            $adjustment = $this->service->create($request->document());
        } catch (DomainException $exception) {
            return $this->refused($exception);
        }

        $edit = to_route('accounting.inventory.adjustments.edit', $adjustment->id);

        if (! $post) {
            return $edit->with('success', __('Saved successfully'));
        }

        try {
            $this->service->post($adjustment);
        } catch (DomainException $exception) {
            return $this->refused($exception, $edit);
        }

        return $edit->with('success', __('Adjustment posted.'));
    }

    public function edit(int $stockAdjustment): Response
    {
        $adjustment = $this->adjustments->findForPage($stockAdjustment);
        $lines = InventoryLines::byMainItem($adjustment->items->map(fn (StockAdjustmentItem $item) => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'barcode' => $item->barcode,
            'description' => $item->description,
            'location_id' => $item->location_id,
            'location_code' => $item->location?->code,
            'quantity' => (float) $item->quantity,
            'counted_quantity' => $item->counted_quantity !== null ? (float) $item->counted_quantity : null,
            'system_quantity' => $item->system_quantity !== null ? (float) $item->system_quantity : null,
            'unit_cost' => (float) $item->unit_cost,
            'notes' => $item->notes,
        ])->all());

        return Inertia::render('Accounting/Inventory/StockAdjustment/Edit', [
            'adjustment' => [
                'type' => $adjustment->type->value,
                'warehouse_id' => $adjustment->warehouse_id,
                'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
                'reason' => $adjustment->reason,
                'notes' => $adjustment->notes,
                'items' => $lines,
            ],
            'record' => [
                ...$this->documentRecord($adjustment),
                'date' => $adjustment->adjustment_date?->format('d/m/Y'),
                'warehouse' => $adjustment->warehouse?->name,
                'type_label' => $adjustment->type->getLabel(),
            ],
            ...$this->formOptions($adjustment->canBeEdited() ? $adjustment->warehouse_id : null, $lines),
        ]);
    }

    public function update(StockAdjustmentRequest $request, int $stockAdjustment): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->update($this->find($stockAdjustment), $request->document()), __('Saved successfully'));
    }

    public function post(StockAdjustmentRequest $request, int $stockAdjustment): RedirectResponse
    {
        $adjustment = $this->find($stockAdjustment);

        return $this->attempt(function () use ($request, $adjustment) {
            if ($adjustment->canBeEdited()) {
                $adjustment = $this->service->update($adjustment, $request->document());
            }

            return $this->service->post($adjustment);
        }, __('Adjustment posted.'));
    }

    public function cancel(int $stockAdjustment): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->cancel($this->find($stockAdjustment)), __('Adjustment cancelled and stock reversed.'));
    }

    public function destroy(int $stockAdjustment): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->service->delete($this->find($stockAdjustment)),
            __('Deleted successfully'),
            fn () => to_route('accounting.inventory.adjustments.index'),
        );
    }

    protected function bulkDeleteQuery(): Builder
    {
        return $this->adjustments->tableQuery();
    }

    /**
     * @param  StockAdjustment  $document
     */
    protected function deleteDocument(Model $document): void
    {
        $this->service->delete($document);
    }

    public function pdf(int $stockAdjustment, InventoryPdfService $pdf): mixed
    {
        return $pdf->stockAdjustment($this->find($stockAdjustment));
    }

    public function info(int $stockAdjustment): JsonResponse
    {
        return response()->json(RecordInfo::of($this->find($stockAdjustment)));
    }

    /**
     * عند تغيير المخزن: مواقعه ورصيد النظام لكل صنف فيه.
     */
    public function warehouse(Request $request): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer'], 'product_ids' => ['array', 'max:5000'], 'product_ids.*' => ['integer']]);

        return response()->json(InventoryLines::warehouseContext($data['warehouse_id'] ?? null, $data['product_ids'] ?? []));
    }

    public function products(Request $request): JsonResponse
    {
        return response()->json(InventoryLines::search($request->string('q')->toString()));
    }

    /**
     * سطر الصنف المختار حسب نوع المستند (الجرد يبدأ العدد بواحد) برصيد النظام.
     */
    public function line(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(StockAdjustmentType::class)],
            'warehouse_id' => ['nullable', 'integer'],
        ]);
        $product = Product::query()->with('translations')->find($data['product_id']);

        if (! $product || $product->isService()) {
            return response()->json(null);
        }

        $line = $this->service->newLine($product, StockAdjustmentType::from($data['type']), $data['warehouse_id'] ?? null);

        return response()->json(InventoryLines::byMainItem([$line])[0]);
    }

    /**
     * كشف الجرد: اصناف المخزن، او نوع المنيوم واحد، دون الموجود في المستند.
     */
    public function countSheet(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'main_product_id' => ['nullable', 'integer'],
            'except' => ['array'],
            'except.*' => ['integer'],
        ]);
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);

        return response()->json(InventoryLines::byMainItem($this->service->countSheetLines($warehouse, $data['main_product_id'] ?? null, $data['except'] ?? [])));
    }

    /**
     * انواع الالمنيوم (اصناف رئيسية) لتحميل كشف جرد نوع واحد.
     */
    public function mainProducts(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        if ($term === '') {
            return response()->json([]);
        }

        $mainIds = ProductSearch::idsWithSubProducts(ProductSearch::find($term, 30)->ids);

        return response()->json(SelectOptions::from(ProductSearch::labels(array_slice($mainIds, 0, 10))));
    }

    private function find(int $id): StockAdjustment
    {
        /** @var StockAdjustment */
        return $this->adjustments->findOrFail($id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function formOptions(?int $warehouseId, array $lines): array
    {
        return [
            'warehouseOptions' => SelectOptions::from(Warehouse::options()),
            'typeOptions' => SelectOptions::from(StockAdjustmentType::options()),
            'context' => InventoryLines::warehouseContext($warehouseId, array_column($lines, 'product_id')),
            'canSeeCost' => (bool) auth()->user()?->can('Stock Value View'),
        ];
    }
}
