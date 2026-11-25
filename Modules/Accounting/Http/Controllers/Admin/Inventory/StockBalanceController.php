<?php

namespace Modules\Accounting\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Services\StockBalancesService;
use Modules\Accounting\Support\SelectOptions;
use Modules\Accounting\Support\StockStatus;
use Modules\Core\Support\AdminTable;
use Modules\Items\Entities\Product;
use Modules\Items\Support\PriceGroupSearch;
use Modules\Items\Support\ProductSearch;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

/**
 * ارصدة المخزون: لكل صنف الموجود والمحجوز والقادم والمتاح وحدوده وموقعه وحالته. الفلاتر في
 * الرابط (filter[warehouse]=..) حتى تفتح الصفحة من صفحة المخزن مفلترة عليه.
 */
class StockBalanceController extends Controller
{
    public function __construct(private readonly StockBalancesService $balances) {}

    public function index(Request $request): Response|RedirectResponse
    {
        // الرابط القديم (Filament): ?tableFilters[warehouse][value]=..
        if ($request->has('tableFilters')) {
            $legacy = (array) $request->input('tableFilters', []);
            $filter = array_filter([
                'warehouse' => $legacy['warehouse']['value'] ?? null,
                'status' => $legacy['status']['value'] ?? null,
            ], fn ($value) => filled($value));

            return to_route('accounting.inventory.stock.index', $filter ? ['filter' => $filter] : []);
        }

        $filters = (array) $request->input('filter', []);
        $warehouseId = filled($filters['warehouse'] ?? null) ? (int) $filters['warehouse'] : null;
        $priceGroupId = filled($filters['price_group'] ?? null) ? (int) $filters['price_group'] : null;
        $canSeeCost = (bool) $request->user()?->can('Stock Value View');
        $query = $this->balances->query($warehouseId, $priceGroupId);

        // «فقط الاصناف التي لها رصيد او حركة» مفعّل افتراضياً؛ filter[with_stock]=0 يعرض الكل.
        if (! array_key_exists('with_stock', $filters)) {
            $this->balances->applyWithStock($query, $warehouseId);
        }

        $table = AdminTable::for($query)
            ->filters(
                AllowedFilter::callback('search', fn (Builder $query, mixed $value) => ProductSearch::apply($query, trim((string) $value))),
                AllowedFilter::callback('warehouse', fn (Builder $query) => $query),
                AllowedFilter::callback('price_group', fn (Builder $query) => $query),
                AllowedFilter::callback('status', fn (Builder $query, mixed $value) => $this->balances->applyStatus($query, (string) $value)),
                AllowedFilter::callback('with_stock', fn (Builder $query, mixed $value) => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? $this->balances->applyWithStock($query, $warehouseId) : $query),
            )
            ->sorts(
                AllowedSort::field('barcode', (new Product)->getTable().'.barcode'),
                AllowedSort::field('stock_on_hand'),
                AllowedSort::field('stock_weight'),
            )
            ->defaultSort('barcode')
            ->rows(fn (Product $product) => ['id' => $product->id, 'product' => $product]);

        $table['data'] = $this->balances->rows(collect($table['data'])->pluck('product'), $warehouseId, $canSeeCost);

        return Inertia::render('Accounting/Inventory/StockBalances/Index', [
            'table' => $table,
            'warehouseId' => $warehouseId,
            'priceGroup' => $priceGroupId ? ['value' => $priceGroupId, 'label' => (string) (PriceGroupSearch::labels([$priceGroupId])[$priceGroupId] ?? '')] : null,
            'groupTotal' => $this->balances->groupTotal($priceGroupId, $warehouseId, $canSeeCost),
            'warehouseOptions' => SelectOptions::from(Warehouse::options()),
            'statusOptions' => SelectOptions::from(StockStatus::options()),
            'canSeeCost' => $canSeeCost,
            'printUrl' => route('accounting.inventory.print.stock-report', array_filter([
                'warehouse' => $warehouseId,
                'price_group' => $priceGroupId,
                'status' => $filters['status'] ?? null,
            ])),
        ]);
    }

    /**
     * مجموعات السعر (التصنيف × اللون) لفلتر «المجموعة»، مثل «الوبين — أبيض».
     */
    public function priceGroups(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        return response()->json($term === '' ? [] : SelectOptions::from(PriceGroupSearch::options($term, 40)));
    }
}
