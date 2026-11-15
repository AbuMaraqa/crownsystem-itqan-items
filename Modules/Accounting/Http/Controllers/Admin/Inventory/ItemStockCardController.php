<?php

namespace Modules\Accounting\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\ProductLocation;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Entities\WarehouseLocation;
use Modules\Accounting\Enums\LocationDirection;
use Modules\Accounting\Services\ItemStockCardService;
use Modules\Accounting\Services\WarehouseLayoutService;
use Modules\Accounting\Support\Quantity;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StatusBadge;
use Modules\Items\Entities\Product;
use Modules\Items\Support\ProductSearch;

/**
 * بطاقة الصنف في المخزن: البحث بالاسم او بقارئ الباركود، الارصدة والحدود، الاماكن (تعيينها
 * لصاحب «Warehouse Location Manage»)، الطلبيات المفتوحة، وحركة الصنف برصيد متراكم.
 */
class ItemStockCardController extends Controller
{
    public function __construct(
        private readonly ItemStockCardService $cards,
        private readonly WarehouseLayoutService $layout,
    ) {}

    public function index(Request $request, ?int $product = null): Response
    {
        $warehouses = Warehouse::options();
        // مخزن واحد يُختار تلقائياً؛ «كل المخازن» = warehouse فارغ.
        $warehouseId = $request->has('warehouse')
            ? ($request->integer('warehouse') ?: null)
            : (count($warehouses) === 1 ? (int) array_key_first($warehouses) : null);
        // الحركة الافتراضية لآخر ستة اشهر؛ ما قبلها يظهر كرصيد افتتاحي.
        $from = $request->has('from') ? ($request->string('from')->toString() ?: null) : now()->subMonths(6)->startOfMonth()->toDateString();
        $to = $request->string('to')->toString() ?: null;
        $record = $product ? Product::query()->with(['translations', 'mainProduct.translations', 'media', 'unit.translations'])->find($product) : null;

        return Inertia::render('Accounting/Inventory/ItemCard/Index', [
            'filters' => ['warehouse' => $warehouseId, 'from' => $from, 'to' => $to],
            'warehouseOptions' => SelectOptions::from($warehouses),
            'product' => $record ? $this->product($record) : null,
            'card' => $record ? $this->card($record, $warehouseId, $from, $to) : null,
            'locationOptions' => SelectOptions::from(WarehouseLocation::options($warehouseId)),
            'directionOptions' => SelectOptions::from(LocationDirection::options()),
            'canSeeCost' => (bool) $request->user()?->can('Stock Value View'),
        ]);
    }

    /**
     * بحث الصنف بالاسم او الباركود (Enter يفتح اول نتيجة).
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        return response()->json($term === '' ? [] : SelectOptions::from(ProductSearch::options($term, 12, itemType: 'goods')));
    }

    /**
     * تعيين موقع للصنف في المخزن المختار (اساسي او اضافي، وسهم بطاقة الرف).
     */
    public function assign(Request $request, int $product): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'is_primary' => ['boolean'],
            'direction' => ['nullable', Rule::enum(LocationDirection::class)],
        ], [], ['location_id' => __('Location')]);
        $record = Product::query()->findOrFail($product);
        $location = WarehouseLocation::query()->findOrFail($data['location_id']);

        $this->layout->assign($record->id, $location, (bool) ($data['is_primary'] ?? false), $data['direction'] ?? null);

        return back()->with('success', __('Location saved.'));
    }

    public function unassign(int $product, int $placement): RedirectResponse
    {
        $row = ProductLocation::query()->where('product_id', $product)->findOrFail($placement);

        try {
            $this->layout->unassign($row);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Location removed.'));
    }

    public function makePrimary(int $product, int $placement): RedirectResponse
    {
        $row = ProductLocation::query()->with('location')->where('product_id', $product)->findOrFail($placement);
        $this->layout->assign($row->product_id, $row->location, true, $row->direction?->value);

        return back()->with('success', __('Location saved.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'image' => $product->image ?: null,
            'main_product' => $product->mainProduct?->name,
            'barcode' => $product->barcode,
            'sku' => $product->sku,
            'weight_per_meter' => (float) $product->weight_per_meter ? Quantity::format($product->weight_per_meter, 3) : null,
            'length' => (float) $product->length ? Quantity::format($product->length, 3) : null,
            'shelf' => $product->shelf,
            'max_limit' => Quantity::format($product->max_limit),
            'min_limit_1' => Quantity::format($product->min_limit_1),
            'min_limit_2' => Quantity::format($product->min_limit_2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Product $product, ?int $warehouseId, ?string $from, ?string $to): array
    {
        $card = $this->cards->card($product, $warehouseId, $from, $to);
        $levels = $card['levels'];
        $main = $card['mainLevels'];
        $fmt = fn ($value, int $decimals = 2): string => Quantity::format($value, $decimals);
        $canSeeCost = (bool) auth()->user()?->can('Stock Value View');
        $cost = null;

        if ($canSeeCost && ! $main && $card['cost']) {
            $average = (float) $card['cost']->average_cost;
            $value = (float) $levels['on_hand'] * $average;
            // المخزون يُعدّ بالقطع: صنف الوزن يعرض ايضاً كلفة الكغم (القيمة ÷ الوزن).
            $cost = ['average' => $fmt($average, 4), 'per_kg' => (float) $levels['weight'] > 0 ? $fmt($value / (float) $levels['weight'], 4) : null, 'value' => $fmt($value)];
        }

        return [
            'levels' => [
                'on_hand' => $fmt($levels['on_hand']),
                'reserved' => $fmt($levels['reserved']),
                'incoming' => $fmt($levels['incoming']),
                'available' => $fmt($levels['available']),
                'available_negative' => (float) $levels['available'] < 0,
                'weight' => (float) $levels['weight'] > 0 ? $fmt($levels['weight']) : null,
            ],
            'status' => ['label' => $card['status']->getLabel(), 'tone' => StatusBadge::tone($card['status']->color())],
            'value' => $canSeeCost && $main ? $fmt($main['value']) : null,
            'cost' => $cost,
            'main' => $main ? [
                'sub_items' => (int) $main['sub_items'],
                'on_hand' => $fmt($main['on_hand']),
                'weight' => $fmt($main['weight']),
                'reserved' => $fmt($main['reserved']),
                'incoming' => $fmt($main['incoming']),
                'available' => $fmt($main['available']),
                'value' => $canSeeCost ? $fmt($main['value']) : null,
            ] : null,
            'sub_items' => $card['subItems']->map(fn (array $row) => [
                'id' => $row['product']->id,
                'code' => $row['product']->barcode ?: $row['product']->sku,
                'name' => $row['product']->name,
                'on_hand' => $fmt($row['levels']['on_hand']),
                'weight' => $fmt($row['levels']['weight']),
                'reserved' => $fmt($row['levels']['reserved']),
                'incoming' => $fmt($row['levels']['incoming']),
                'available' => $fmt($row['levels']['available']),
                'available_negative' => (float) $row['levels']['available'] < 0,
                'value' => $canSeeCost ? $fmt($row['levels']['value']) : null,
            ])->values()->all(),
            'warehouses' => $card['byWarehouse']->map(fn ($row) => [
                'name' => $row->warehouse?->name,
                'quantity' => $fmt($row->quantity),
                'weight' => (float) $row->weight ? $fmt($row->weight) : null,
            ])->values()->all(),
            'placements' => $card['placements']->map(fn (ProductLocation $place) => [
                'id' => $place->id,
                'code' => $place->location?->code,
                'is_primary' => (bool) $place->is_primary,
                'arrow' => $place->direction?->arrow(),
                'warehouse' => $place->warehouse?->name,
                'quantity' => $card['locationStock']->has($place->location_id) ? $fmt($card['locationStock'][$place->location_id]['quantity']) : null,
            ])->values()->all(),
            'sales_orders' => $card['salesOrders']->map(fn (array $row) => [...$row, 'quantity' => $fmt($row['quantity'])])->values()->all(),
            'purchase_orders' => $card['purchaseOrders']->map(fn (array $row) => [...$row, 'quantity' => $fmt($row['quantity'])])->values()->all(),
            'movements' => [
                'opening' => $fmt($card['movements']['opening']),
                'rows' => $card['movements']['rows']->map(fn (StockMovement $movement) => [
                    'id' => $movement->id,
                    'date' => $movement->movement_date?->format('d/m/Y'),
                    'type' => StatusBadge::of($movement->type),
                    'reference_no' => $movement->reference_no,
                    'url' => $movement->source_url,
                    'warehouse' => $movement->warehouse?->name,
                    'location' => $movement->location?->code,
                    'in' => (float) $movement->quantity > 0 ? $fmt($movement->quantity) : null,
                    'out' => (float) $movement->quantity < 0 ? $fmt(-(float) $movement->quantity) : null,
                    'balance' => $fmt($movement->running_balance),
                    'unit_cost' => $canSeeCost ? $fmt($movement->unit_cost, 4) : null,
                ])->values()->all(),
            ],
        ];
    }
}
