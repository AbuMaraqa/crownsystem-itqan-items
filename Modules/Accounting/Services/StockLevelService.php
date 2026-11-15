<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Entities\ProductCost;
use Modules\Accounting\Entities\ProductLocation;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Entities\StockCostLayer;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Entities\WarehouseLocation;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Enums\PurchaseOrderDeliveryType;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Support\StockStatus;
use Modules\Items\Entities\Product;

/**
 * ارصدة الاصناف كما تظهر لموظف المخزن والمبيعات (مثل شاشة النظام القديم):
 * - الموجود: الرصيد الفعلي في المخزن.
 * - المحجوز (كمية طلبيات البيع): طلبيات بيع مؤكدة لم تُسلَّم بعد.
 * - القادم (كمية طلبيات الشراء): طلبيات شراء مؤكدة للمخزن لم تُستلم بعد.
 * - المتاح للبيع = الموجود − المحجوز.
 */
class StockLevelService
{
    private const OPEN_SALES_STATUSES = [2, 3, 4];

    private const OPEN_PURCHASE_STATUSES = [2, 4];

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array{on_hand:float, weight:float, reserved:float, incoming:float, available:float}>
     */
    public function levels(array $productIds, ?int $warehouseId = null): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        $onHand = StockBalance::query()
            ->whereIn('product_id', $productIds)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as quantity, SUM(weight) as weight')
            ->get()
            ->keyBy('product_id');
        $reserved = $this->reserved($productIds, $warehouseId);
        $incoming = $this->incoming($productIds, $warehouseId);
        $levels = [];

        foreach ($productIds as $productId) {
            $quantity = round((float) ($onHand[$productId]->quantity ?? 0), 4);
            $reservedQuantity = round((float) ($reserved[$productId] ?? 0), 4);

            $levels[$productId] = [
                'on_hand' => $quantity,
                'weight' => round((float) ($onHand[$productId]->weight ?? 0), 4),
                'reserved' => $reservedQuantity,
                'incoming' => round((float) ($incoming[$productId] ?? 0), 4),
                'available' => round($quantity - $reservedQuantity, 4),
            ];
        }

        return $levels;
    }

    /**
     * الصنف الرئيسي (نوع الالمنيوم، مثل «المنيوم سبير ابيض») كما في اتقان: الكغم الفعلي والقيمة
     * صفّه هو، ولا يُجمعان من مقاطعه ولا يُسوَّى احد الرصيدين بالآخر. عدد القطع والمحجوز والقادم
     * مجموع مقاطعه. هذا هو المصدر الوحيد لرصيد الصنف الرئيسي في كل الشاشات.
     *
     * @param  array<int, int>  $mainIds
     * @return array<int, array{on_hand:float, weight:float, reserved:float, incoming:float, available:float, value:float, sub_items:int}>
     */
    public function mainItemLevels(array $mainIds, ?int $warehouseId = null): array
    {
        $totals = [];
        $subLevels = $this->subItemLevels($mainIds, $warehouseId);
        $pieces = $subLevels === [] ? [] : array_flip(Product::query()
            ->whereIn('main_product_id', array_keys($subLevels))
            ->where('pricing_type', 'weight')
            ->pluck('id')
            ->all());
        $own = $this->levels(array_keys($subLevels), $warehouseId);
        $values = $this->stockValues(array_keys($subLevels), $warehouseId);

        foreach ($subLevels as $mainId => $subItems) {
            $total = ['on_hand' => 0.0, 'weight' => 0.0, 'reserved' => 0.0, 'incoming' => 0.0, 'available' => 0.0, 'value' => 0.0];

            foreach ($subItems as $subId => $levels) {
                foreach (['on_hand', 'reserved', 'incoming', 'available'] as $key) {
                    $total[$key] = round($total[$key] + $levels[$key], 4);
                }

                // صنف فرعي ليس مقطع وزن يبقى بوزنه وقيمته كما كان.
                if (! isset($pieces[$subId])) {
                    $total['weight'] = round($total['weight'] + $levels['weight'], 4);
                    $total['value'] = round($total['value'] + $levels['value'], 4);
                }
            }

            $total['weight'] = round($total['weight'] + $own[$mainId]['weight'], 4);
            $total['value'] = round($total['value'] + ($values[$mainId] ?? 0), 4);

            $totals[$mainId] = $total + ['sub_items' => count($subItems)];
        }

        return $totals;
    }

    /**
     * رصيد صنف واحد كما يظهر في بطاقته: رصيده الخاص، والصنف الرئيسي من mainItemLevels (عدد
     * القطع من مقاطعه، والكغم من صفّه) فلا يُعدّ كغمه مرتين.
     * مصدر واحد لبطاقة الصنف المخزنية وتبويب «المخزون» في تعريف الصنف.
     *
     * @return array{levels: array{on_hand:float, weight:float, reserved:float, incoming:float, available:float}, mainLevels: ?array}
     */
    public function productLevels(int $productId, ?int $warehouseId = null): array
    {
        $levels = $this->levels([$productId], $warehouseId)[$productId];
        $mainLevels = $this->mainItemLevels([$productId], $warehouseId)[$productId] ?? null;

        if ($mainLevels) {
            $levels['on_hand'] = $mainLevels['on_hand'];
            $levels['weight'] = $mainLevels['weight'];
            $levels['reserved'] = round($levels['reserved'] + $mainLevels['reserved'], 4);
            $levels['incoming'] = round($levels['incoming'] + $mainLevels['incoming'], 4);
            $levels['available'] = round($levels['on_hand'] - $levels['reserved'], 4);
        }

        return ['levels' => $levels, 'mainLevels' => $mainLevels];
    }

    /**
     * ملخص مخزون الصنف للعرض فقط في تعريفه: الارصدة، الحالة من حدوده، متوسط التكلفة،
     * والرصيد في كل مخزن مع موقعه الاساسي. الصنف الرئيسي: كغمه من صفّه وعدد القطع من مقاطعه.
     *
     * @return array{levels: array, mainLevels: ?array, status: StockStatus, cost: ?ProductCost, warehouses: array<int, array{name:?string, quantity:float, weight:float, location:?string}>}
     */
    public function productSummary(Product $product): array
    {
        ['levels' => $levels, 'mainLevels' => $mainLevels] = $this->productLevels($product->id);
        $subIds = Product::query()->where('main_product_id', $product->id)->pluck('id')->all();

        // بلا فروع: رصيد الصنف نفسه. بفروع: الكمية من الفروع والوزن من صف الصنف الرئيسي.
        // الشرط المنطقي يُكتب تعبيراً لا ربطاً رقمياً، لأن PostgreSQL لا يقبل عدداً بعد AND/NOT.
        $hasSubs = $subIds !== [] ? '1 = 1' : '1 = 0';
        $balances = StockBalance::query()
            ->whereIn('product_id', [...$subIds, $product->id])
            ->groupBy('warehouse_id')
            ->selectRaw(
                "warehouse_id, SUM(CASE WHEN product_id = ? AND ({$hasSubs}) THEN 0 ELSE quantity END) as quantity, SUM(CASE WHEN product_id = ? OR NOT ({$hasSubs}) THEN weight ELSE 0 END) as weight",
                [$product->id, $product->id]
            )
            ->get()
            ->keyBy('warehouse_id');
        $placements = ProductLocation::query()
            ->with('location')
            ->where('product_id', $product->id)
            ->where('is_primary', true)
            ->get()
            ->keyBy('warehouse_id');
        $warehouseIds = $balances->keys()->merge($placements->keys())->unique();
        $warehouses = Warehouse::query()->with('translations')->whereKey($warehouseIds)->get()->keyBy('id');

        return [
            'levels' => $levels,
            'mainLevels' => $mainLevels,
            'status' => StockStatus::for($product, $levels['on_hand']),
            'cost' => $mainLevels ? null : ProductCost::query()->where('product_id', $product->id)->first(),
            'warehouses' => $warehouseIds->map(fn ($id) => [
                'name' => $warehouses->get($id)?->name,
                'quantity' => round((float) $balances->get($id)?->quantity, 4),
                'weight' => round((float) $balances->get($id)?->weight, 4),
                'location' => $placements->get($id)?->location?->code,
            ])->values()->all(),
        ];
    }

    /**
     * ارصدة الاصناف الفرعية لكل صنف رئيسي. المقطع بلا قيمة؛ غيره بقيمة طبقاته.
     *
     * @param  array<int, int>  $mainIds
     * @return array<int, array<int, array{on_hand:float, weight:float, reserved:float, incoming:float, available:float, value:float}>>
     */
    public function subItemLevels(array $mainIds, ?int $warehouseId = null): array
    {
        $mainIds = array_values(array_unique(array_filter(array_map('intval', $mainIds))));

        if ($mainIds === []) {
            return [];
        }

        $subItems = Product::query()->whereIn('main_product_id', $mainIds)->pluck('main_product_id', 'id');
        $pieces = Product::query()->whereIn('main_product_id', $mainIds)->where('pricing_type', 'weight')->pluck('id')->flip();

        if ($subItems->isEmpty()) {
            return [];
        }

        $levels = $this->levels($subItems->keys()->all(), $warehouseId);
        $values = $this->stockValues($subItems->keys()->all(), $warehouseId);
        $result = [];

        foreach ($subItems as $subId => $mainId) {
            $result[(int) $mainId][(int) $subId] = $levels[$subId] + [
                'value' => $pieces->has($subId) ? 0.0 : ($values[$subId] ?? 0.0),
            ];
        }

        return $result;
    }

    /**
     * قيمة المخزون لكل صنف: مجموع ما بقي من طبقات FIFO في المخزن (او في كل المخازن).
     *
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    public function stockValues(array $productIds, ?int $warehouseId = null): array
    {
        if ($productIds === []) {
            return [];
        }

        return StockCostLayer::query()
            ->whereIn('product_id', $productIds)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(remaining_value) as value')
            ->pluck('value', 'product_id')
            ->map(fn ($value) => round((float) $value, 4))
            ->all();
    }

    /**
     * يمنع الخروج ان لم تكفِ الكمية، ويذكر كل الاصناف الناقصة دفعة واحدة.
     * صاحب صلاحية الرصيد السالب لا يُمنع، كما في اتقان.
     *
     * @param  array<int, array{product_id:int, location_id:?int, quantity:float}>  $lines
     */
    public function assertAvailable(int $warehouseId, array $lines): void
    {
        if (StockLedgerService::allowsNegativeStock()) {
            return;
        }

        $required = [];

        foreach ($lines as $line) {
            $key = $line['product_id'].':'.($line['location_id'] ?: 0);
            $required[$key] = round(($required[$key] ?? 0) + (float) $line['quantity'], 4);
            $totalKey = $line['product_id'].':*';
            $required[$totalKey] = round(($required[$totalKey] ?? 0) + (float) $line['quantity'], 4);
        }

        $productIds = array_values(array_unique(array_column($lines, 'product_id')));
        $balances = StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'location_id', 'quantity']);
        $shortages = [];

        foreach ($required as $key => $quantity) {
            [$productId, $location] = explode(':', $key);

            if ($location === '0') {
                continue;
            }

            $available = $location === '*'
                ? (float) $balances->where('product_id', (int) $productId)->sum('quantity')
                : (float) $balances->where('product_id', (int) $productId)->where('location_id', (int) $location)->sum('quantity');

            if ($available + 0.00005 < $quantity) {
                $shortages[] = [(int) $productId, $location === '*' ? null : (int) $location, $available, $quantity];
            }
        }

        if ($shortages === []) {
            return;
        }

        $products = Product::query()->with('translations')->whereIn('id', array_column($shortages, 0))->get()->keyBy('id');
        $locations = WarehouseLocation::query()->whereIn('id', array_filter(array_column($shortages, 1)))->pluck('code', 'id');
        $warehouse = Warehouse::query()->with('translations')->find($warehouseId)?->name;

        $messages = array_map(fn (array $shortage) => __(':item:location — available :available, required :required', [
            'item' => trim(($products[$shortage[0]]?->barcode ? $products[$shortage[0]]->barcode.' ' : '').($products[$shortage[0]]?->name ?? '')),
            'location' => $shortage[1] ? ' ('.$locations[$shortage[1]].')' : '',
            'available' => $this->format($shortage[2]),
            'required' => $this->format($shortage[3]),
        ]), $shortages);

        throw new DomainException(__('Insufficient stock in :warehouse:', ['warehouse' => $warehouse])."\n".implode("\n", $messages));
    }

    /**
     * مواقع كل صنف في المخزن مع رصيد كل موقع، الاساسي اولاً.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array<int, array{location_id:int, code:string, quantity:float, is_primary:bool, direction:?string}>>
     */
    public function locations(array $productIds, int $warehouseId): array
    {
        if ($productIds === []) {
            return [];
        }

        $prefix = config('accounting.table_prefix');

        $placements = DB::table($prefix.'product_locations as placements')
            ->join($prefix.'warehouse_locations as locations', 'locations.id', '=', 'placements.location_id')
            ->where('placements.warehouse_id', $warehouseId)
            ->whereIn('placements.product_id', $productIds)
            ->get(['placements.product_id', 'placements.location_id', 'placements.is_primary', 'placements.direction', 'locations.code']);
        $balances = StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->where('quantity', '!=', 0)
            ->get(['product_id', 'location_id', 'quantity']);
        $codes = WarehouseLocation::query()->whereIn('id', $balances->pluck('location_id')->filter()->unique())->pluck('code', 'id');
        $result = [];

        foreach ($placements as $placement) {
            $result[$placement->product_id][$placement->location_id] = [
                'location_id' => (int) $placement->location_id,
                'code' => $placement->code,
                'quantity' => 0.0,
                'is_primary' => (bool) $placement->is_primary,
                'direction' => $placement->direction,
            ];
        }

        foreach ($balances as $balance) {
            $result[$balance->product_id][$balance->location_id] ??= [
                'location_id' => (int) $balance->location_id,
                'code' => $balance->location_id ? ($codes[$balance->location_id] ?? '#'.$balance->location_id) : __('Unassigned'),
                'quantity' => 0.0,
                'is_primary' => false,
                'direction' => null,
            ];
            $result[$balance->product_id][$balance->location_id]['quantity'] += (float) $balance->quantity;
        }

        return array_map(fn (array $rows) => collect($rows)->sortByDesc('is_primary')->values()->all(), $result);
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    public function reserved(array $productIds, ?int $warehouseId = null): array
    {
        return $this->sumByProduct($this->openSalesRows($productIds, $warehouseId));
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    public function incoming(array $productIds, ?int $warehouseId = null): array
    {
        return $this->sumByProduct($this->openPurchaseRows($productIds, $warehouseId));
    }

    /**
     * طلبيات البيع المفتوحة التي تحجز الصنف، مع الكمية المتبقية في كل منها.
     *
     * @return array<int, array{order_id:int, quantity:float}>
     */
    public function reservedByOrder(int $productId, ?int $warehouseId = null): array
    {
        return $this->sumByOrder($this->openSalesRows([$productId], $warehouseId));
    }

    /**
     * طلبيات الشراء المفتوحة القادمة للصنف، مع الكمية المتبقية في كل منها.
     *
     * @return array<int, array{order_id:int, quantity:float}>
     */
    public function incomingByOrder(int $productId, ?int $warehouseId = null): array
    {
        return $this->sumByOrder($this->openPurchaseRows([$productId], $warehouseId));
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array{product_id:int, order_id:int, outstanding:float}>
     */
    private function openSalesRows(array $productIds, ?int $warehouseId = null): array
    {
        $prefix = config('accounting.table_prefix');
        $orders = fn ($query) => $query
            ->whereIn('orders.status', self::OPEN_SALES_STATUSES)
            ->whereNull('orders.deleted_at')
            ->when($warehouseId, fn ($q) => $q->where('orders.warehouse_id', $warehouseId))
            ->when(Schema::hasColumn($prefix.'sales_orders', 'fulfillment_type'), fn ($q) => $q->where(fn ($w) => $w->whereNull('orders.fulfillment_type')->orWhere('orders.fulfillment_type', '!=', 'direct')))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from($prefix.'purchase_orders as direct')
                ->whereColumn('direct.direct_sales_order_id', 'orders.id')
                ->where('direct.delivery_type', PurchaseOrderDeliveryType::DIRECT->value)
                ->where('direct.status', '!=', PurchaseOrderStatus::CANCELLED->value())
                ->whereNull('direct.deleted_at'));

        $pieces = DB::table($prefix.'sales_order_item_pieces as pieces')
            ->join($prefix.'sales_order_items as items', 'items.id', '=', 'pieces.sales_order_item_id')
            ->join($prefix.'sales_orders as orders', 'orders.id', '=', 'items.sales_order_id')
            ->whereNull('items.deleted_at')
            ->whereIn('pieces.product_id', $productIds)
            ->where($orders)
            ->get(['pieces.id', 'pieces.product_id', 'pieces.piece_count as quantity', 'orders.id as order_id']);

        $lines = DB::table($prefix.'sales_order_items as items')
            ->join($prefix.'sales_orders as orders', 'orders.id', '=', 'items.sales_order_id')
            ->whereNull('items.deleted_at')
            ->whereIn('items.product_id', $productIds)
            ->when(Schema::hasColumn($prefix.'sales_order_items', 'line_type'), fn ($query) => $query->where('items.line_type', '!=', 'service'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($prefix.'sales_order_item_pieces as p')->whereColumn('p.sales_order_item_id', 'items.id'))
            ->where($orders)
            ->get(['items.id', 'items.product_id', 'items.quantity', 'orders.id as order_id']);

        $delivered = $this->documentQuantities('delivery_note_items', 'delivery_notes', 'delivery_note_id', 'sales_order_item_piece_id', $pieces->pluck('id')->all())
            + $this->documentQuantities('delivery_note_items', 'delivery_notes', 'delivery_note_id', 'sales_order_item_id', $lines->pluck('id')->all(), 'item');

        return $this->outstandingRows($pieces, $lines, $delivered);
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array{product_id:int, order_id:int, outstanding:float}>
     */
    private function openPurchaseRows(array $productIds, ?int $warehouseId = null): array
    {
        $prefix = config('accounting.table_prefix');
        $orders = fn ($query) => $query
            ->whereIn('orders.status', self::OPEN_PURCHASE_STATUSES)
            ->whereNull('orders.deleted_at')
            ->where('orders.delivery_type', PurchaseOrderDeliveryType::WAREHOUSE->value)
            ->when($warehouseId, fn ($q) => $q->where('orders.warehouse_id', $warehouseId));

        $pieces = DB::table($prefix.'purchase_order_item_pieces as pieces')
            ->join($prefix.'purchase_order_items as items', 'items.id', '=', 'pieces.purchase_order_item_id')
            ->join($prefix.'purchase_orders as orders', 'orders.id', '=', 'items.purchase_order_id')
            ->whereNull('items.deleted_at')
            ->whereIn('pieces.product_id', $productIds)
            ->where($orders)
            ->get(['pieces.id', 'pieces.product_id', 'pieces.piece_count as quantity', 'orders.id as order_id']);

        $lines = DB::table($prefix.'purchase_order_items as items')
            ->join($prefix.'purchase_orders as orders', 'orders.id', '=', 'items.purchase_order_id')
            ->whereNull('items.deleted_at')
            ->whereIn('items.product_id', $productIds)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($prefix.'purchase_order_item_pieces as p')->whereColumn('p.purchase_order_item_id', 'items.id'))
            ->where($orders)
            ->get(['items.id', 'items.product_id', 'items.quantity', 'orders.id as order_id']);

        $received = $this->documentQuantities('goods_receipt_items', 'goods_receipts', 'goods_receipt_id', 'purchase_order_item_piece_id', $pieces->pluck('id')->all())
            + $this->documentQuantities('goods_receipt_items', 'goods_receipts', 'goods_receipt_id', 'purchase_order_item_id', $lines->pluck('id')->all(), 'item');

        return $this->outstandingRows($pieces, $lines, $received);
    }

    /**
     * الكميات المرحّلة في سندات الاستلام/التسليم لكل قطعة او بند.
     *
     * @return array<string, float> «piece:ID» او «item:ID»
     */
    private function documentQuantities(string $linesTable, string $documentsTable, string $documentKey, string $column, array $ids, string $kind = 'piece'): array
    {
        if ($ids === []) {
            return [];
        }

        $prefix = config('accounting.table_prefix');

        return DB::table($prefix.$linesTable.' as lines')
            ->join($prefix.$documentsTable.' as documents', 'documents.id', '=', 'lines.'.$documentKey)
            ->where('documents.status', InventoryDocumentStatus::POSTED->value())
            ->whereNull('documents.deleted_at')
            ->whereIn('lines.'.$column, $ids)
            ->when($kind === 'item', fn ($query) => $query->whereNull('lines.'.str_replace('_item_id', '_item_piece_id', $column)))
            ->groupBy('lines.'.$column)
            ->selectRaw('lines.'.$column.' as id, SUM(lines.quantity) as quantity')
            ->get()
            ->mapWithKeys(fn ($row) => [$kind.':'.$row->id => (float) $row->quantity])
            ->all();
    }

    /**
     * المتبقي لكل قطعة/بند = المطلوب − المنفَّذ في سندات مرحّلة.
     */
    private function outstandingRows($pieces, $lines, array $done): array
    {
        $rows = [];

        foreach ([['piece', $pieces], ['item', $lines]] as [$kind, $collection]) {
            foreach ($collection as $line) {
                $outstanding = max(round((float) $line->quantity - ($done[$kind.':'.$line->id] ?? 0), 4), 0);

                if ($outstanding > 0) {
                    $rows[] = ['product_id' => (int) $line->product_id, 'order_id' => (int) $line->order_id, 'outstanding' => $outstanding];
                }
            }
        }

        return $rows;
    }

    private function sumByProduct(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $result[$row['product_id']] = round(($result[$row['product_id']] ?? 0) + $row['outstanding'], 4);
        }

        return $result;
    }

    private function sumByOrder(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $result[$row['order_id']] ??= ['order_id' => $row['order_id'], 'quantity' => 0.0];
            $result[$row['order_id']]['quantity'] = round($result[$row['order_id']]['quantity'] + $row['outstanding'], 4);
        }

        return array_values($result);
    }

    private function format(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.') ?: '0';
    }
}
