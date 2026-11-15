<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\ProductLocation;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Entities\WarehouseLocation;
use Modules\Items\Entities\Product;

/**
 * تخطيط المخزن: توليد المواقع (الممرات × الارقام)، وربط الاصناف بمواقعها.
 */
class WarehouseLayoutService
{
    /**
     * يولّد مواقع لكل ممر من الممرات ولكل رقم من..الى. الموجود لا يتكرر.
     *
     * @param  array<int, string>  $aisles
     * @return int عدد المواقع الجديدة
     */
    public function generate(Warehouse $warehouse, array $aisles, int $fromPosition, int $toPosition, ?string $zone = null): int
    {
        $aisles = collect($aisles)->map(fn ($aisle) => mb_strtoupper(trim((string) $aisle)))->filter()->unique()->values();

        if ($aisles->isEmpty()) {
            throw new DomainException(__('Enter at least one aisle.'));
        }

        if ($fromPosition < 1 || $toPosition < $fromPosition || $toPosition - $fromPosition > 200) {
            throw new DomainException(__('Invalid position range.'));
        }

        return DB::transaction(function () use ($warehouse, $aisles, $fromPosition, $toPosition, $zone) {
            $existing = WarehouseLocation::query()->where('warehouse_id', $warehouse->id)->pluck('code')->flip();
            $created = 0;

            foreach ($aisles as $aisle) {
                for ($position = $fromPosition; $position <= $toPosition; $position++) {
                    $code = WarehouseLocation::makeCode($aisle, $position);

                    if ($existing->has($code)) {
                        continue;
                    }

                    WarehouseLocation::create([
                        'warehouse_id' => $warehouse->id,
                        'zone' => filled($zone) ? trim($zone) : null,
                        'aisle' => $aisle,
                        'position' => $position,
                        'code' => $code,
                    ]);

                    $existing->put($code, true);
                    $created++;
                }
            }

            return $created;
        });
    }

    /**
     * «A-T» او «AA-AJ» او «A,B,C» الى قائمة ممرات.
     *
     * @return array<int, string>
     */
    public function parseAisles(string $input): array
    {
        $aisles = [];

        foreach (preg_split('/[\s,،]+/u', mb_strtoupper(trim($input))) as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^([A-Z]+)-([A-Z]+)$/', $part, $matches) && strlen($matches[1]) === strlen($matches[2]) && $matches[1] <= $matches[2]) {
                $letter = $matches[1];

                for ($guard = 0; $guard < 100; $guard++) {
                    $aisles[] = $letter;

                    if ($letter === $matches[2]) {
                        break;
                    }

                    $letter = function_exists('str_increment') ? str_increment($letter) : ++$letter;
                }

                continue;
            }

            $aisles[] = $part;
        }

        return array_values(array_unique($aisles));
    }

    /**
     * يضع الصنف في الموقع. الموقع الاساسي واحد لكل صنف في كل مخزن.
     */
    public function assign(int $productId, WarehouseLocation $location, bool $primary = true, ?string $direction = null, ?string $notes = null): ProductLocation
    {
        return DB::transaction(function () use ($productId, $location, $primary, $direction, $notes) {
            if ($primary) {
                ProductLocation::query()
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $location->warehouse_id)
                    ->where('location_id', '!=', $location->id)
                    ->update(['is_primary' => false]);
            }

            $placement = ProductLocation::query()->firstOrNew([
                'product_id' => $productId,
                'location_id' => $location->id,
            ]);

            $placement->fill([
                'warehouse_id' => $location->warehouse_id,
                'is_primary' => $primary,
                'direction' => filled($direction) ? $direction : $placement->direction,
                'notes' => $notes ?? $placement->notes,
            ])->save();

            return $placement;
        });
    }

    /**
     * يُزال الصنف من الموقع اذا لم يبقَ له فيه رصيد.
     */
    public function unassign(ProductLocation $placement): void
    {
        $quantity = (float) StockBalance::query()
            ->where('product_id', $placement->product_id)
            ->where('warehouse_id', $placement->warehouse_id)
            ->where('location_id', $placement->location_id)
            ->value('quantity');

        if ($quantity > 0) {
            throw new DomainException(__('The item still has stock in this location. Transfer it first.'));
        }

        $placement->delete();
    }

    /**
     * عند الاستلام في موقع: اول موقع للصنف في المخزن يصبح اساسياً، وغيره يُسجَّل اضافياً
     * (حين لا يتسع الاساسي) فيظهر للعامل اين يجد الصنف.
     */
    public function rememberLocation(int $productId, int $warehouseId, int $locationId): void
    {
        $placements = ProductLocation::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->get();

        if ($placements->contains('location_id', $locationId)) {
            return;
        }

        ProductLocation::create([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'is_primary' => $placements->isEmpty(),
        ]);
    }

    public function primaryLocationId(int $productId, int $warehouseId): ?int
    {
        $id = ProductLocation::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('is_primary', true)
            ->value('location_id');

        return $id ? (int) $id : null;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, int> product_id => location_id
     */
    public function primaryLocationIds(array $productIds, int $warehouseId): array
    {
        if ($productIds === []) {
            return [];
        }

        return ProductLocation::query()
            ->whereIn('product_id', $productIds)
            ->where('warehouse_id', $warehouseId)
            ->where('is_primary', true)
            ->pluck('location_id', 'product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * ينقل «رقم الرف» من بطاقات الاصناف (النظام القديم، مثل E10) الى مواقع المخزن:
     * يُنشئ الموقع ان لم يوجد ويجعله الموقع الاساسي للصنف.
     *
     * @return array{locations:int, assigned:int, skipped:int}
     */
    public function importFromProductShelves(Warehouse $warehouse, bool $overwritePrimary = false): array
    {
        $result = ['locations' => 0, 'assigned' => 0, 'skipped' => 0];

        Product::query()
            ->whereNotNull('shelf')
            ->where('shelf', '!=', '')
            ->select(['id', 'shelf'])
            ->chunkById(1000, function (Collection $products) use ($warehouse, $overwritePrimary, &$result) {
                DB::transaction(function () use ($products, $warehouse, $overwritePrimary, &$result) {
                    $locations = WarehouseLocation::query()->where('warehouse_id', $warehouse->id)->get()->keyBy('code');
                    $primaries = $this->primaryLocationIds($products->pluck('id')->all(), $warehouse->id);

                    foreach ($products as $product) {
                        $parsed = $this->parseShelf((string) $product->shelf);

                        if (! $parsed) {
                            $result['skipped']++;

                            continue;
                        }

                        if (isset($primaries[$product->id]) && ! $overwritePrimary) {
                            continue;
                        }

                        $location = $locations->get($parsed['code']);

                        if (! $location) {
                            $location = WarehouseLocation::create([
                                'warehouse_id' => $warehouse->id,
                                'aisle' => $parsed['aisle'],
                                'position' => $parsed['position'],
                                'code' => $parsed['code'],
                            ]);
                            $locations->put($location->code, $location);
                            $result['locations']++;
                        }

                        $this->assign($product->id, $location);
                        $result['assigned']++;
                    }
                });
            });

        return $result;
    }

    /**
     * «E10» او «e 10» او «AG-7» الى ممر ورقم.
     *
     * @return array{aisle:string, position:?int, code:string}|null
     */
    public function parseShelf(string $shelf): ?array
    {
        $shelf = mb_strtoupper(trim($shelf));

        if (! preg_match('/^([A-Z]{1,3})\s*[-\/]?\s*(\d{1,3})?$/', $shelf, $matches)) {
            return null;
        }

        $position = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : null;

        return [
            'aisle' => $matches[1],
            'position' => $position,
            'code' => WarehouseLocation::makeCode($matches[1], $position),
        ];
    }

    /**
     * يُحذف الموقع اذا لم يبقَ فيه رصيد.
     */
    public function deleteLocation(WarehouseLocation $location): void
    {
        if (StockBalance::query()->where('location_id', $location->id)->where('quantity', '!=', 0)->exists()) {
            throw new DomainException(__('The location still has stock. Transfer it first.'));
        }

        $location->delete();
    }

    /**
     * خريطة المخزن: المنطقة ← الممر ← الرقم، مع عدد الاصناف ورصيدها في كل موقع، وتمييز مواقع
     * الصنف المبحوث عنه. الطوابق بترتيب ارقامها على الاعمدة (العلوي 1..6 قبل السفلي 7..12).
     *
     * @return array{zones: array<int, array<string, mixed>>, stats: array<string, int|float>}
     */
    public function map(int $warehouseId, string $highlight = ''): array
    {
        $locations = WarehouseLocation::query()->where('warehouse_id', $warehouseId)->ordered()->get();
        $placed = ProductLocation::query()->where('warehouse_id', $warehouseId)->groupBy('location_id')->selectRaw('location_id, COUNT(*) as items')->pluck('items', 'location_id');
        $stock = StockBalance::query()->where('warehouse_id', $warehouseId)->where('location_id', '>', 0)->groupBy('location_id')->selectRaw('location_id, SUM(quantity) as quantity')->pluck('quantity', 'location_id');
        $highlighted = $this->highlightedLocations($warehouseId, $highlight);
        $zones = [];

        foreach ($locations as $location) {
            $zone = $location->zone ?: '';
            $zones[$zone]['aisles'][$location->aisle][(int) $location->position] = [
                'id' => $location->id,
                'code' => $location->code,
                'active' => (bool) $location->is_active,
                'items' => (int) ($placed[$location->id] ?? 0),
                'quantity' => (float) ($stock[$location->id] ?? 0),
                'highlight' => $highlighted->contains($location->id),
            ];
            $zones[$zone]['positions'][(int) $location->position] = true;
        }

        foreach ($zones as $name => $zone) {
            $positions = array_keys($zone['positions']);
            sort($positions);
            $zones[$name]['positions'] = $positions;
        }

        uasort($zones, fn (array $a, array $b) => ($a['positions'][0] ?? 0) <=> ($b['positions'][0] ?? 0));

        $result = [];

        foreach ($zones as $name => $zone) {
            $result[] = [
                'name' => (string) $name,
                'positions' => $zone['positions'],
                'aisles' => collect($zone['aisles'])->map(fn (array $cells, string|int $aisle) => [
                    'aisle' => (string) $aisle,
                    'cells' => collect($zone['positions'])->map(fn (int $position) => $cells[$position] ?? null)->all(),
                ])->values()->all(),
            ];
        }

        return [
            'zones' => $result,
            'stats' => [
                'locations' => $locations->count(),
                'occupied' => $placed->count(),
                'items' => (int) ProductLocation::query()->where('warehouse_id', $warehouseId)->distinct('product_id')->count('product_id'),
                'unassigned' => (float) StockBalance::query()->where('warehouse_id', $warehouseId)->where('location_id', 0)->sum('quantity'),
            ],
        ];
    }

    /**
     * اصناف موقع واحد: المسجلة فيه وما له رصيد فيه، الاكبر رصيداً اولاً.
     *
     * @return Collection<int, array{product: Product, placement: ?ProductLocation, quantity: float}>
     */
    public function locationContents(WarehouseLocation $location): Collection
    {
        $placements = ProductLocation::query()->where('location_id', $location->id)->get()->keyBy('product_id');
        $balances = StockBalance::query()->where('location_id', $location->id)->where('warehouse_id', $location->warehouse_id)->pluck('quantity', 'product_id');
        $products = Product::query()->with(['translations', 'media'])->whereIn('id', $placements->keys()->merge($balances->keys())->unique())->get();

        return $products->map(fn (Product $product) => [
            'product' => $product,
            'placement' => $placements->get($product->id),
            'quantity' => (float) ($balances[$product->id] ?? 0),
        ])->sortByDesc('quantity')->values();
    }

    /**
     * المواقع التي فيها الصنف المبحوث عنه (لتلوينها على الخريطة).
     *
     * @return Collection<int, int>
     */
    private function highlightedLocations(int $warehouseId, string $highlight): Collection
    {
        if (trim($highlight) === '') {
            return collect();
        }

        $ids = \Modules\Items\Support\ProductSearch::find($highlight, 50)->ids;

        return ProductLocation::query()->where('warehouse_id', $warehouseId)->whereIn('product_id', $ids)->pluck('location_id')
            ->merge(StockBalance::query()->where('warehouse_id', $warehouseId)->whereIn('product_id', $ids)->where('quantity', '>', 0)->pluck('location_id'))
            ->unique();
    }
}
