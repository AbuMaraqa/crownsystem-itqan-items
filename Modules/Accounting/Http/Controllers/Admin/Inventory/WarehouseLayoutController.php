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
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Entities\WarehouseLocation;
use Modules\Accounting\Enums\LocationDirection;
use Modules\Accounting\Services\WarehouseLayoutService;
use Modules\Accounting\Support\Quantity;
use Modules\Accounting\Support\SelectOptions;
use Modules\Items\Support\ProductSearch;

/**
 * خريطة المخزن كما هي على الارض: الممرات (الحروف على الجسور) × ارقام المواقع (على الاعمدة).
 * كل خانة موقع، ولونها حسب امتلائها؛ اختيارها (?location=) يعرض اصنافها وكمياتها ويسمح بوضع
 * صنف فيها. التوليد والاستيراد والتعيين في WarehouseLayoutService.
 */
class WarehouseLayoutController extends Controller
{
    public function __construct(private readonly WarehouseLayoutService $layout) {}

    public function index(Request $request, ?int $warehouse = null): Response
    {
        $warehouses = Warehouse::options();
        $warehouseId = $warehouse && isset($warehouses[$warehouse]) ? $warehouse : ((int) array_key_first($warehouses) ?: null);
        $highlight = $request->string('highlight')->toString();
        $location = $warehouseId && $request->integer('location')
            ? WarehouseLocation::query()->where('warehouse_id', $warehouseId)->find($request->integer('location'))
            : null;

        return Inertia::render('Accounting/Inventory/WarehouseLayout/Index', [
            'warehouseId' => $warehouseId,
            'warehouseOptions' => SelectOptions::from($warehouses),
            'directionOptions' => SelectOptions::from(LocationDirection::options()),
            'highlight' => $highlight,
            'map' => $warehouseId ? $this->layout->map($warehouseId, $highlight) : ['zones' => [], 'stats' => null],
            'selected' => $location ? [
                'id' => $location->id,
                'code' => $location->code,
                'zone' => $location->zone,
                'active' => (bool) $location->is_active,
                'rows' => $this->layout->locationContents($location)->map(fn (array $row) => [
                    'product_id' => $row['product']->id,
                    'name' => $row['product']->name,
                    'barcode' => $row['product']->barcode,
                    'image' => $row['product']->image ?: null,
                    'placement_id' => $row['placement']?->id,
                    'placement' => $row['placement'] ? ($row['placement']->is_primary ? __('Primary') : __('Overflow')) : null,
                    'arrow' => $row['placement']?->direction?->arrow(),
                    'quantity' => Quantity::format($row['quantity']),
                    'removable' => $row['placement'] && $row['quantity'] <= 0,
                ])->values()->all(),
            ] : null,
        ]);
    }

    /**
     * يولّد المواقع لممرات (F-T, AA-AJ, H) × ارقام من..الى، في منطقة اختيارية.
     */
    public function generate(Request $request, int $warehouse): RedirectResponse
    {
        $data = $request->validate([
            'zone' => ['nullable', 'string', 'max:100'],
            'aisles' => ['required', 'string', 'max:255'],
            'from' => ['required', 'integer', 'min:1'],
            'to' => ['required', 'integer', 'min:1'],
        ], [], ['aisles' => __('Aisles')]);
        $record = Warehouse::query()->findOrFail($warehouse);

        try {
            $count = $this->layout->generate($record, $this->layout->parseAisles($data['aisles']), (int) $data['from'], (int) $data['to'], ($data['zone'] ?? null) ?: null);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __(':count locations created.', ['count' => $count]));
    }

    /**
     * يستورد رقم الرف من النظام القديم (E10): ينشئ المواقع ويضع كل صنف في موقعه.
     */
    public function importShelves(int $warehouse): RedirectResponse
    {
        $result = $this->layout->importFromProductShelves(Warehouse::query()->findOrFail($warehouse));

        return back()->with('success', __('Imported shelf numbers: :assigned items placed, :locations new locations, :skipped not recognized.', $result));
    }

    public function products(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        return response()->json($term === '' ? [] : SelectOptions::from(ProductSearch::options($term, 10, itemType: 'goods')));
    }

    /**
     * يضع صنفاً في الموقع (اساسي او اضافي، مع سهم بطاقة الرف).
     */
    public function place(Request $request, int $location): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'is_primary' => ['boolean'],
            'direction' => ['nullable', Rule::enum(LocationDirection::class)],
        ]);

        $this->layout->assign((int) $data['product_id'], WarehouseLocation::query()->findOrFail($location), (bool) ($data['is_primary'] ?? false), $data['direction'] ?? null);

        return back()->with('success', __('Location saved.'));
    }

    public function unplace(int $placement): RedirectResponse
    {
        try {
            $this->layout->unassign(ProductLocation::query()->findOrFail($placement));
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Location removed.'));
    }

    public function toggle(int $location): RedirectResponse
    {
        $record = WarehouseLocation::query()->findOrFail($location);
        $record->update(['is_active' => ! $record->is_active]);

        return back();
    }

    public function destroyLocation(int $location): RedirectResponse
    {
        $record = WarehouseLocation::query()->findOrFail($location);

        try {
            $this->layout->deleteLocation($record);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('accounting.inventory.layout', ['warehouse' => $record->warehouse_id])->with('success', __('Deleted successfully'));
    }
}
