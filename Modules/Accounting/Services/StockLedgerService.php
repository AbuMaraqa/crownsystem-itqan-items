<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\ProductCost;
use Modules\Accounting\Entities\ProductLocation;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Entities\StockCostLayer;
use Modules\Accounting\Entities\StockCostLayerEntry;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Entities\WarehouseLocation;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Items\Entities\Product;

/**
 * المكان الوحيد الذي يغيّر ارصدة المخزون. كل تغيير = حركة في الدفتر + تحديث الرصيد
 * + طبقات تكلفة FIFO، داخل معاملة واحدة مع اقفال الصفوف. ProductCost يبقى للعرض: القيمة ومتوسطها.
 *
 * - الدخول بكلفته (سعر الشراء بالعملة الاساسية) يضيف طبقة في مخزنه.
 * - الخروج يستهلك اقدم طبقات الصنف في نفس المخزن بسعرها، ولو كان صفراً، كما في اتقان
 *   (Cost-System=FIFO)؛ ما زاد على الطبقات تكلفته صفر. ويُمنع ان كانت الكمية غير كافية،
 *   الا لمن له صلاحية الرصيد السالب (NegativeAmount في اتقان) فيصبح الرصيد سالباً.
 * - العكس يعيد نفس الطبقات تماماً: كل استهلاك مربوط بحركته. عكس دخول استُهلكت طبقته يُمنع.
 * - النقل يحمل الطبقات بسعرها وترتيبها، فلا تتغير القيمة الكلية.
 * - خروج بلا موقع محدد يسحب من الموقع الاساسي ثم الاضافية ثم غير المحدد.
 * - صنف الوزن كما في اتقان: الصنف الرئيسي يتحرك بالكغم الفعلي وقيمته، والمقطع (صنف وزن تحت
 *   صنف رئيسي) بالعدد بقيمة صفر ووزنه المحسوب للمعلومة. الرصيدان مستقلان ولا يُسوَّى احدهما
 *   بالآخر.
 */
class StockLedgerService
{
    private const EPSILON = 0.00005;

    public const NEGATIVE_STOCK_PERMISSION = 'Stock Allow Negative';

    /**
     * صاحب الصلاحية يُخرج اكثر من الرصيد، كما في اتقان.
     */
    public static function allowsNegativeStock(): bool
    {
        return (bool) auth()->user()?->can(self::NEGATIVE_STOCK_PERMISSION);
    }

    /**
     * @param  array{product_id:int, warehouse_id:int, location_id?:?int, quantity:float, weight?:float, unit_cost:float, total_cost?:?float, layers_from?:?Collection, type:StockMovementType, date:string, source?:?Model, source_line_id?:?int, reference_no?:?string, description?:?string}  $data
     */
    public function receive(array $data): StockMovement
    {
        $quantity = round((float) $data['quantity'], 4);

        if ($quantity <= 0) {
            throw new DomainException(__('Stock quantity must be greater than zero.'));
        }

        return DB::transaction(function () use ($data, $quantity) {
            $productId = (int) $data['product_id'];
            $warehouseId = (int) $data['warehouse_id'];
            $isPiece = $this->isPiece($productId);
            $unitCost = $isPiece ? 0.0 : max(round((float) $data['unit_cost'], 6), 0);
            $carried = $isPiece ? collect() : $this->carriedEntries($data['layers_from'] ?? null);
            // total_cost الصريح يبقي قيمة السطر كما هي بلا تقريب كلفة الوحدة.
            $totalCost = match (true) {
                $isPiece => 0.0,
                $carried->isNotEmpty() => round((float) $carried->sum('value'), 4),
                default => round((float) ($data['total_cost'] ?? $quantity * $unitCost), 4),
            };
            $weight = round((float) ($data['weight'] ?? 0), 4);
            $locationId = (int) ($data['location_id'] ?? 0);

            $this->applyCost($this->lockedCost($productId), $quantity, $totalCost, $unitCost);

            $balance = $this->lockedBalance($productId, $warehouseId, $locationId);
            $balance->quantity = round((float) $balance->quantity + $quantity, 4);
            $balance->weight = round((float) $balance->weight + $weight, 4);
            $balance->save();

            $movement = $this->record($data, $locationId, $quantity, $weight, $unitCost, $totalCost);

            if (! $isPiece) {
                $this->addLayers($movement, $quantity, $unitCost, $totalCost, $carried);
            }

            $this->syncProductStock($productId);

            return $movement;
        });
    }

    /**
     * @param  array{product_id:int, warehouse_id:int, location_id?:?int, quantity:float, weight?:?float, unit_cost?:?float, type:StockMovementType, date:string, source?:?Model, source_line_id?:?int, reference_no?:?string, description?:?string}  $data
     * @return Collection<int, StockMovement> حركة لكل موقع سُحب منه
     */
    public function issue(array $data): Collection
    {
        $quantity = round((float) $data['quantity'], 4);

        if ($quantity <= 0) {
            throw new DomainException(__('Stock quantity must be greater than zero.'));
        }

        return DB::transaction(function () use ($data, $quantity) {
            $productId = (int) $data['product_id'];
            $warehouseId = (int) $data['warehouse_id'];
            $cost = $this->lockedCost($productId);
            $isPiece = $this->isPiece($productId);
            $explicitCost = array_key_exists('unit_cost', $data) && $data['unit_cost'] !== null ? round((float) $data['unit_cost'], 6) : null;

            $allocations = $this->allocate($productId, $warehouseId, $data['location_id'] ?? null, $quantity);
            $requestedWeight = isset($data['weight']) && $data['weight'] !== null ? round((float) $data['weight'], 4) : null;
            $movements = collect();
            $remainingWeight = $requestedWeight;

            foreach ($allocations as $index => [$balance, $take]) {
                $isLast = $index === array_key_last($allocations);
                $weight = $this->issuedWeight($balance, $take, $quantity, $requestedWeight, $remainingWeight, $isLast);
                $remainingWeight = $remainingWeight !== null ? round($remainingWeight - $weight, 4) : null;
                $consumed = $isPiece ? [] : $this->consumeLayers($productId, $warehouseId, $take);
                $layersValue = round(array_sum(array_column($consumed, 'value')), 4);
                $lineCost = match (true) {
                    $isPiece => 0.0,
                    $explicitCost !== null => round($take * $explicitCost, 4),
                    default => $layersValue,
                };
                $unitCost = match (true) {
                    $isPiece => 0.0,
                    $explicitCost !== null => $explicitCost,
                    default => round($layersValue / $take, 6),
                };

                $balance->quantity = round((float) $balance->quantity - $take, 4);
                // الرصيد السالب (بالصلاحية) يحمل وزناً سالباً مثله.
                $balance->weight = $balance->quantity < 0
                    ? round((float) $balance->weight - $weight, 4)
                    : max(round((float) $balance->weight - $weight, 4), 0);

                if (abs((float) $balance->quantity) < self::EPSILON) {
                    $balance->quantity = 0;
                    $balance->weight = 0;
                }

                $balance->save();

                $movement = $this->record($data, (int) $balance->location_id, -$take, -$weight, $unitCost, -$lineCost);
                $this->linkEntries($movement, $consumed);
                $movements->push($movement);
            }

            $totalCost = round((float) $movements->sum(fn (StockMovement $movement) => -(float) $movement->total_cost), 4);
            $this->applyCost($cost, -$quantity, -$totalCost);

            $this->syncProductStock($productId);

            return $movements;
        });
    }

    /**
     * المقطع: صنف وزن تحت صنف رئيسي. يتحرك بالعدد وقيمته صفر؛ القيمة على صنفه الرئيسي.
     */
    public function isPiece(int $productId): bool
    {
        return Product::query()->whereKey($productId)->where('pricing_type', 'weight')->whereNotNull('main_product_id')->exists();
    }

    /**
     * الصنف الرئيسي يتحرك بالكغم: له اصناف فرعية، او صنف وزن بلا صنف رئيسي ولا قياس قطعة
     * يحوّل وزنه الى عدد.
     */
    public function isMainItem(Product $product): bool
    {
        if ($product->pricing_type !== 'weight' || filled($product->main_product_id)) {
            return Product::query()->where('main_product_id', $product->id)->exists();
        }

        return (float) $product->length * (float) $product->weight_per_meter <= 0
            || Product::query()->where('main_product_id', $product->id)->exists();
    }

    /**
     * تعديل قيمة صنف بلا تغيير كميته (فرق سعر فاتورة عن قيمة استلامه): حركة بكمية صفر تحمل الفرق
     * وتعيد تسعير طبقات حركات الدخول المذكورة. ما استُهلك منها يبقى خارج التعديل، فيُطبَّق نصيب
     * الباقي فقط ولا تنزل طبقة تحت الصفر؛ يعيد ما طُبّق فعلاً.
     *
     * @param  array{product_id:int, warehouse_id:int, amount:float, movements?:array<int, int>, type:StockMovementType, date:string, source?:?Model, source_line_id?:?int, reference_no?:?string, description?:?string}  $data
     */
    public function revalue(array $data): float
    {
        return DB::transaction(function () use ($data) {
            $cost = $this->lockedCost((int) $data['product_id']);
            $layers = StockCostLayer::query()
                ->whereIn('stock_movement_id', $data['movements'] ?? [])
                ->lockForUpdate()
                ->orderBy('sequence')
                ->orderBy('id')
                ->get();
            $original = (float) $layers->sum('quantity');
            $open = $layers->filter(fn (StockCostLayer $layer) => (float) $layer->remaining_quantity > self::EPSILON)->values();

            if ($original <= 0 || $open->isEmpty()) {
                return 0.0;
            }

            $target = round((float) $data['amount'] * (float) $open->sum('remaining_quantity') / $original, 4);
            $left = $target;
            $entries = [];

            foreach ($open as $index => $layer) {
                $share = $index === $open->count() - 1
                    ? $left
                    : round((float) $data['amount'] * (float) $layer->remaining_quantity / $original, 4);
                $share = max($share, -(float) $layer->remaining_value);
                $left = round($left - $share, 4);

                if ($share == 0.0) {
                    continue;
                }

                $entries[] = [
                    'stock_cost_layer_id' => $layer->id,
                    'kind' => StockCostLayerEntry::REVALUE,
                    'value' => $share,
                    'previous_unit_cost' => $layer->unit_cost,
                    'remaining_at_entry' => $layer->remaining_quantity,
                ];

                $layer->remaining_value = round((float) $layer->remaining_value + $share, 4);
                $layer->unit_cost = round((float) $layer->remaining_value / (float) $layer->remaining_quantity, 6);
                $layer->save();
            }

            $applied = round(array_sum(array_column($entries, 'value')), 4);

            if ($applied == 0.0) {
                return 0.0;
            }

            $this->applyCost($cost, 0.0, $applied);
            $this->linkEntries($this->record($data, 0, 0.0, 0.0, 0.0, $applied), $entries);

            return $applied;
        });
    }

    /**
     * يعكس كل حركات المستند (عند الغائه) بنفس الموقع والكمية والكلفة، وتعود الطبقات كما كانت.
     * عكس الدخول يُمنع ان كانت البضاعة قد صُرفت او استُهلكت طبقته.
     */
    public function reverse(Model $source, string $date, ?string $referenceNo = null): void
    {
        // تنبيهات الاصناف تُقيَّم بعد عكس كل الحركات، لا من رصيد مؤقت في منتصفها.
        DB::transaction(fn () => app(StockAlertService::class)->batch(function () use ($source, $date, $referenceNo) {
            $movements = StockMovement::query()
                ->where('sourceable_type', $source->getMorphClass())
                ->where('sourceable_id', $source->getKey())
                ->where('type', '!=', StockMovementType::REVERSAL->value)
                ->orderByDesc('id')
                ->get();

            foreach ($movements as $movement) {
                $payload = [
                    'product_id' => $movement->product_id,
                    'warehouse_id' => $movement->warehouse_id,
                    'location_id' => $movement->location_id ?: null,
                    'quantity' => abs((float) $movement->quantity),
                    'weight' => abs((float) $movement->weight),
                    'unit_cost' => (float) $movement->unit_cost,
                    'total_cost' => abs((float) $movement->total_cost),
                    'type' => StockMovementType::REVERSAL,
                    'date' => $date,
                    'source' => $source,
                    'source_line_id' => $movement->source_line_id,
                    'reference_no' => $referenceNo ?? $movement->reference_no,
                    'description' => __('Reversal for').' '.($movement->reference_no ?? ''),
                ];

                if ((float) $movement->quantity == 0.0) {
                    $this->reverseRevalue($movement, $payload);
                } elseif ((float) $movement->quantity > 0) {
                    $payload['location_id'] = $movement->location_id;
                    $this->reverseReceive($movement, $payload);
                } else {
                    $this->reverseIssue($movement, $payload);
                }
            }
        }));
    }

    public function onHand(int $productId, ?int $warehouseId = null, ?int $locationId = null): float
    {
        return round((float) StockBalance::query()
            ->where('product_id', $productId)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->when($locationId !== null, fn ($query) => $query->where('location_id', (int) $locationId))
            ->sum('quantity'), 4);
    }

    public function averageCost(int $productId): float
    {
        return round((float) ProductCost::query()->where('product_id', $productId)->value('average_cost'), 6);
    }

    /**
     * تكلفة الكغم من مخزون الصنف، ايّاً كانت وحدة رصيده:
     * - الصنف الرئيسي يُخزَّن بالكغم: متوسطه هو تكلفة الكغم.
     * - المقطع (صنف فرعي قديم) بلا قيمة: قيمته على صنفه الرئيسي، فتكلفة كغمه تكلفة كغم الرئيسي.
     * - صنف وزن يُخزَّن بالقطعة (لون مقطع): متوسط القطعة ÷ وزن القطعة (الطول × وزن المتر).
     * - غير ذلك: المتوسط كما هو.
     */
    public function costPerKg(Product $product): float
    {
        if ($this->isPiece((int) $product->id)) {
            return $this->averageCost((int) $product->main_product_id);
        }

        $perPiece = (float) $product->length * (float) $product->weight_per_meter;

        if ($product->pricing_type !== 'weight' || $perPiece <= 0 || $this->isMainItem($product)) {
            return $this->averageCost((int) $product->id);
        }

        return round($this->averageCost((int) $product->id) / $perPiece, 6);
    }

    /**
     * متوسط التكلفة المرجّح لعدة اصناف باستعلام واحد؛ الصنف الذي لم يُشترَ بعد تكلفته صفر.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    public function averageCosts(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));

        if ($ids === []) {
            return [];
        }

        $costs = ProductCost::query()->whereIn('product_id', $ids)->pluck('average_cost', 'product_id');

        return collect($ids)->mapWithKeys(fn (int $id): array => [$id => round((float) ($costs[$id] ?? 0), 6)])->all();
    }

    /**
     * عكس دخول من موقعه تماماً (location_id = 0 يعني «بلا موقع»): تُزال طبقته، وتُمنع ان
     * استُهلك منها شيء لان كلفة ما خرج منها قيّدت على مستند آخر. حركة دخول سبقت الطبقات تُخرج
     * من اقدم الطبقات.
     */
    private function reverseReceive(StockMovement $movement, array $payload): void
    {
        $productId = (int) $payload['product_id'];
        $warehouseId = (int) $payload['warehouse_id'];
        $locationId = (int) ($payload['location_id'] ?? 0);
        $quantity = round((float) $payload['quantity'], 4);
        $balance = $this->lockedBalance($productId, $warehouseId, $locationId);

        if ((float) $balance->quantity + self::EPSILON < $quantity) {
            throw new DomainException(__('Cannot cancel: :item was already issued from the warehouse (available :available, required :required).', [
                'item' => $this->productLabel($productId),
                'available' => $this->formatQuantity((float) $balance->quantity),
                'required' => $this->formatQuantity($quantity),
            ]));
        }

        $cost = $this->lockedCost($productId);
        $layers = StockCostLayer::query()->where('stock_movement_id', $movement->id)->lockForUpdate()->get();
        $entries = [];

        if ($layers->isNotEmpty()) {
            $remaining = (float) $layers->sum('remaining_quantity');

            if ($remaining + self::EPSILON < $quantity) {
                throw new DomainException(__('Cannot cancel: :item was already issued from the warehouse (available :available, required :required).', [
                    'item' => $this->productLabel($productId),
                    'available' => $this->formatQuantity($remaining),
                    'required' => $this->formatQuantity($quantity),
                ]));
            }

            $totalCost = round((float) $layers->sum('remaining_value'), 4);
            StockCostLayer::query()->whereKey($layers->modelKeys())->delete();
        } elseif ($this->isPiece($productId)) {
            $totalCost = round((float) ($payload['total_cost'] ?? $quantity * (float) $payload['unit_cost']), 4);
        } else {
            $entries = $this->consumeLayers($productId, $warehouseId, $quantity);
            $totalCost = round(array_sum(array_column($entries, 'value')), 4);
        }

        $weight = min(round((float) ($payload['weight'] ?? 0), 4), (float) $balance->weight);

        $balance->quantity = round((float) $balance->quantity - $quantity, 4);
        $balance->weight = round((float) $balance->weight - $weight, 4);
        $balance->save();

        $this->applyCost($cost, -$quantity, -$totalCost);

        $reversal = $this->record($payload, $locationId, -$quantity, -$weight, (float) $payload['unit_cost'], -$totalCost);
        $this->linkEntries($reversal, $entries);
        $this->syncProductStock($productId);
    }

    /**
     * عكس خروج: تعود الكمية والقيمة الى نفس الطبقات التي استُهلكت. خروج سبق الطبقات يعود طبقة
     * جديدة بكلفته.
     */
    private function reverseIssue(StockMovement $movement, array $payload): void
    {
        $productId = (int) $payload['product_id'];
        $warehouseId = (int) $payload['warehouse_id'];
        $locationId = (int) ($payload['location_id'] ?? 0);
        $quantity = round((float) $payload['quantity'], 4);
        $totalCost = round((float) ($payload['total_cost'] ?? 0), 4);
        $weight = round((float) ($payload['weight'] ?? 0), 4);
        $cost = $this->lockedCost($productId);

        $entries = StockCostLayerEntry::query()
            ->where('stock_movement_id', $movement->id)
            ->where('kind', StockCostLayerEntry::ISSUE)
            ->with('layer')
            ->get();

        foreach ($entries as $entry) {
            $layer = $entry->layer;
            $layer->remaining_quantity = round((float) $layer->remaining_quantity + (float) $entry->quantity, 4);
            $layer->remaining_value = round((float) $layer->remaining_value + (float) $entry->value, 4);
            $layer->save();
            $entry->delete();
        }

        $this->applyCost($cost, $quantity, $totalCost, (float) $payload['unit_cost']);

        $balance = $this->lockedBalance($productId, $warehouseId, $locationId);
        $balance->quantity = round((float) $balance->quantity + $quantity, 4);
        $balance->weight = round((float) $balance->weight + $weight, 4);
        $balance->save();

        $reversal = $this->record($payload, $locationId, $quantity, $weight, (float) $payload['unit_cost'], $totalCost);
        $restored = (float) $entries->sum('value');

        if (! $this->isPiece($productId) && round($totalCost - $restored, 4) > 0) {
            $this->addLayers($reversal, round($quantity - (float) $entries->sum('quantity'), 4), (float) $payload['unit_cost'], round($totalCost - $restored, 4), collect());
        }

        $this->syncProductStock($productId);
    }

    /**
     * عكس اعادة التقييم: يعود سعر الطبقة وقيمتها، ويُمنع ان استُهلك منها بعد التقييم بسعره.
     */
    private function reverseRevalue(StockMovement $movement, array $payload): void
    {
        $productId = (int) $payload['product_id'];
        $cost = $this->lockedCost($productId);
        $entries = StockCostLayerEntry::query()
            ->where('stock_movement_id', $movement->id)
            ->where('kind', StockCostLayerEntry::REVALUE)
            ->with('layer')
            ->get();

        foreach ($entries as $entry) {
            $layer = $entry->layer;

            if ((float) $layer->remaining_quantity + self::EPSILON < (float) $entry->remaining_at_entry) {
                throw new DomainException(__('Cannot cancel: :item was already issued from the warehouse (available :available, required :required).', [
                    'item' => $this->productLabel($productId),
                    'available' => $this->formatQuantity((float) $layer->remaining_quantity),
                    'required' => $this->formatQuantity((float) $entry->remaining_at_entry),
                ]));
            }

            $layer->remaining_value = round((float) $layer->remaining_value - (float) $entry->value, 4);
            $layer->unit_cost = $entry->previous_unit_cost;
            $layer->save();
            $entry->delete();
        }

        $this->applyCost($cost, 0.0, -(float) $movement->total_cost);
        $this->record($payload, 0, 0.0, 0.0, 0.0, -(float) $movement->total_cost);
    }

    /**
     * ما تحمله حركة نقل: استهلاك حركات الخروج بطبقاته.
     *
     * @param  Collection<int, StockMovement>|null  $movements
     * @return Collection<int, StockCostLayerEntry>
     */
    private function carriedEntries(?Collection $movements): Collection
    {
        if (! $movements || $movements->isEmpty()) {
            return collect();
        }

        return StockCostLayerEntry::query()
            ->whereIn('stock_movement_id', $movements->pluck('id'))
            ->where('kind', StockCostLayerEntry::ISSUE)
            ->with('layer')
            ->orderBy('id')
            ->get();
    }

    /**
     * طبقة لحركة الدخول؛ حركة النقل تعطي طبقة لكل طبقة حملتها بسعرها وترتيبها.
     *
     * @param  Collection<int, StockCostLayerEntry>  $carried
     */
    private function addLayers(StockMovement $movement, float $quantity, float $unitCost, float $totalCost, Collection $carried): void
    {
        foreach ($carried as $entry) {
            $this->newLayer($movement, (float) $entry->quantity, (float) $entry->layer->unit_cost, (float) $entry->value, (int) $entry->layer->sequence);
        }

        $rest = $carried->isEmpty() ? $quantity : round($quantity - (float) $carried->sum('quantity'), 4);

        // جزء النقل الذي خرج بلا طبقة يبقى بكلفة صفر.
        if ($rest > self::EPSILON) {
            $this->newLayer($movement, $rest, $carried->isEmpty() ? $unitCost : 0.0, $carried->isEmpty() ? $totalCost : 0.0, null);
        }
    }

    private function newLayer(StockMovement $movement, float $quantity, float $unitCost, float $value, ?int $sequence): void
    {
        $layer = StockCostLayer::query()->create([
            'product_id' => $movement->product_id,
            'warehouse_id' => $movement->warehouse_id,
            'stock_movement_id' => $movement->id,
            'sequence' => $sequence ?? 0,
            'quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'unit_cost' => $unitCost,
            'remaining_value' => $value,
        ]);

        if ($sequence === null) {
            $layer->update(['sequence' => $layer->id]);
        }
    }

    /**
     * يستهلك الكمية من اقدم طبقات الصنف في المخزن. ما زاد على الطبقات بلا كلفة، كما في اتقان
     * حين لا يبقى سطر شراء يُربط به الخروج.
     *
     * @return array<int, array{stock_cost_layer_id:int, kind:string, quantity:float, value:float}>
     */
    private function consumeLayers(int $productId, int $warehouseId, float $quantity): array
    {
        $layers = StockCostLayer::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('sequence')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $entries = [];
        $needed = $quantity;

        foreach ($layers as $layer) {
            if ($needed <= self::EPSILON) {
                break;
            }

            $available = (float) $layer->remaining_quantity;
            $take = round(min($available, $needed), 4);
            // الطبقة المستنفدة تعطي باقي قيمتها كله فلا يبقى فرق تقريب.
            $value = abs($take - $available) < self::EPSILON ? (float) $layer->remaining_value : round($take * (float) $layer->unit_cost, 4);

            $layer->remaining_quantity = round($available - $take, 4);
            $layer->remaining_value = round((float) $layer->remaining_value - $value, 4);
            $layer->save();

            $entries[] = ['stock_cost_layer_id' => $layer->id, 'kind' => StockCostLayerEntry::ISSUE, 'quantity' => $take, 'value' => $value];
            $needed = round($needed - $take, 4);
        }

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function linkEntries(StockMovement $movement, array $entries): void
    {
        foreach ($entries as $entry) {
            StockCostLayerEntry::query()->create(array_merge($entry, ['stock_movement_id' => $movement->id]));
        }
    }

    /**
     * ProductCost للعرض: الكمية والقيمة، والمتوسط منهما. نفاد الكمية يصفّر القيمة.
     */
    private function applyCost(ProductCost $cost, float $quantity, float $value, ?float $whenEmpty = null): void
    {
        $cost->quantity = round((float) $cost->quantity + $quantity, 4);
        $cost->value = $cost->quantity > self::EPSILON ? max(round((float) $cost->value + $value, 4), 0) : 0;
        $cost->average_cost = $cost->quantity > self::EPSILON ? round($cost->value / $cost->quantity, 6) : ($whenEmpty ?? $cost->average_cost);
        $cost->save();
    }

    /**
     * يوزّع الكمية المطلوبة على مواقع الصنف في المخزن.
     *
     * @return array<int, array{0: StockBalance, 1: float}>
     */
    private function allocate(int $productId, int $warehouseId, ?int $locationId, float $quantity): array
    {
        $balances = StockBalance::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->where('quantity', '>', 0)
            ->lockForUpdate()
            ->get();

        $available = round((float) $balances->sum('quantity'), 4);
        $allowNegative = self::allowsNegativeStock();

        if ($available + self::EPSILON < $quantity && ! $allowNegative) {
            $location = $locationId ? WarehouseLocation::query()->whereKey($locationId)->value('code') : null;

            throw new DomainException(__('Insufficient stock for :item in :warehouse: available :available, required :required.', [
                'item' => $this->productLabel($productId),
                'warehouse' => trim((Warehouse::query()->with('translations')->find($warehouseId)?->name ?? '').($location ? ' / '.$location : '')),
                'available' => $this->formatQuantity($available),
                'required' => $this->formatQuantity($quantity),
            ]));
        }

        $locationOrder = ProductLocation::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->pluck('location_id')
            ->values()
            ->flip();

        $ordered = $balances->sortBy(function (StockBalance $balance) use ($locationOrder) {
            $rank = $locationOrder->has($balance->location_id) ? $locationOrder[$balance->location_id] : 1000;

            return sprintf('%05d-%010d', $balance->location_id ? $rank : 999, $balance->id);
        })->values();

        $allocations = [];
        $remaining = $quantity;

        foreach ($ordered as $balance) {
            if ($remaining <= self::EPSILON) {
                break;
            }

            $take = round(min((float) $balance->quantity, $remaining), 4);
            $allocations[] = [$balance, $take];
            $remaining = round($remaining - $take, 4);
        }

        // ما زاد على الرصيد (بالصلاحية) يخرج من الموقع المطلوب او الاساسي فيصبح سالباً.
        if ($remaining > self::EPSILON) {
            $target = $locationId ?: (int) ($locationOrder->keys()->first() ?? 0);
            $index = collect($allocations)->search(fn (array $allocation): bool => (int) $allocation[0]->location_id === $target);

            if ($index !== false) {
                $allocations[$index][1] = round($allocations[$index][1] + $remaining, 4);
            } else {
                $allocations[] = [$this->lockedBalance($productId, $warehouseId, $target), $remaining];
            }
        }

        return $allocations;
    }

    /**
     * وزن ما يخرج: الوزن المطلوب موزعاً على المواقع، او متوسط وزن القطعة في الموقع.
     */
    private function issuedWeight(StockBalance $balance, float $take, float $quantity, ?float $requestedWeight, ?float $remainingWeight, bool $isLast): float
    {
        if ($requestedWeight !== null) {
            return $isLast ? max((float) $remainingWeight, 0) : round($requestedWeight * $take / $quantity, 4);
        }

        if ((float) $balance->quantity <= self::EPSILON) {
            return 0;
        }

        return round((float) $balance->weight * $take / (float) $balance->quantity, 4);
    }

    private function record(array $data, int $locationId, float $quantity, float $weight, float $unitCost, float $totalCost): StockMovement
    {
        $type = $data['type'] instanceof StockMovementType ? $data['type'] : StockMovementType::from($data['type']);
        $source = $data['source'] ?? null;

        return StockMovement::create([
            'product_id' => (int) $data['product_id'],
            'warehouse_id' => (int) $data['warehouse_id'],
            'location_id' => $locationId,
            'movement_date' => $data['date'],
            'type' => $type->value,
            'quantity' => $quantity,
            'weight' => $weight,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'sourceable_type' => $source?->getMorphClass(),
            'sourceable_id' => $source?->getKey(),
            'source_line_id' => $data['source_line_id'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'description' => $data['description'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    private function lockedCost(int $productId): ProductCost
    {
        ProductCost::query()->insertOrIgnore([
            'product_id' => $productId,
            'quantity' => 0,
            'value' => 0,
            'average_cost' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ProductCost::query()->where('product_id', $productId)->lockForUpdate()->firstOrFail();
    }

    private function lockedBalance(int $productId, int $warehouseId, int $locationId): StockBalance
    {
        StockBalance::query()->insertOrIgnore([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'quantity' => 0,
            'weight' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StockBalance::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * «الكمية» ووزنها في بطاقة الصنف وشجرة الاصناف = مجموع ارصدته في كل المخازن،
     * ثم مقارنة الرصيد الجديد بحدود الصنف (قسم المتابعة والتنبيهات) داخل نفس المعاملة.
     */
    private function syncProductStock(int $productId): void
    {
        $totals = StockBalance::query()->where('product_id', $productId)->selectRaw('COALESCE(SUM(quantity), 0) as quantity, COALESCE(SUM(weight), 0) as weight')->first();

        Product::query()->whereKey($productId)->update([
            'stock_quantity' => $totals->quantity,
            'stock_weight' => $totals->weight,
        ]);

        app(StockAlertService::class)->evaluate($productId);
    }

    private function productLabel(int $productId): string
    {
        $product = Product::query()->with('translations')->find($productId);

        return trim(($product?->barcode ? $product->barcode.' - ' : '').($product?->name ?? '#'.$productId));
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.') ?: '0';
    }
}
