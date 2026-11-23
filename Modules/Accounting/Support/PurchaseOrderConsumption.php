<?php

namespace Modules\Accounting\Support;

use DomainException;
use Illuminate\Support\Collection;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderItem;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Accounting\Enums\ConsolidatedPurchaseOrderStatus;

/**
 * حساب ما استُهلك من بنود طلبيات الشراء داخل الطلبيات التجميعية، حتى لا تظهر طلبية الشراء
 * (او بندها او صنفها الفرعي) بعد سحب كامل كميتها. مصدر واحد لصفحتي الاضافة والتعديل
 * (Vue) .
 *
 * $excludedOrderId: الطلبية التجميعية الحالية عند التعديل، فلا يُحتسب ما فيها مستهلكاً.
 */
final class PurchaseOrderConsumption
{
    public function __construct(private readonly ?int $excludedOrderId = null) {}

    public static function for(?int $excludedOrderId = null): self
    {
        return new self($excludedOrderId);
    }

    /**
     * الكميات المستهلكة مجمّعة حسب معرّف بند طلبية الشراء، ومعها الاصناف الفرعية (القطع)
     * المسحوبة من البند الموزون.
     *
     * البند الموزون يُسحب صنفاً فرعياً صنفاً فرعياً، وكل قطعة في الطلبية التجميعية تحفظ
     * مصدرها. بند تجميعي بلا قطعة لها مصدر (بند وزن بلا اصناف فرعية، او بند سُحب قبل حفظ
     * المصدر) يعني ان البند سُحب كاملاً.
     *
     * @param  array<int, int>  $purchaseOrderItemIds
     * @return array<int, array{quantity: float, total_weight: float, piece_ids: array<int, int>, whole_line: bool}>
     */
    public function consumed(array $purchaseOrderItemIds = []): array
    {
        if ($purchaseOrderItemIds === []) {
            return [];
        }

        $consumed = [];

        $this->activeItems()
            ->whereIn('source_purchase_order_item_id', $purchaseOrderItemIds)
            ->with('pieces:id,consolidated_purchase_order_item_id,source_purchase_order_item_piece_id')
            ->get(['id', 'source_purchase_order_item_id', 'quantity', 'total_weight'])
            ->each(function (ConsolidatedPurchaseOrderItem $item) use (&$consumed): void {
                $sourceId = (int) $item->source_purchase_order_item_id;
                $pieceIds = $item->pieces
                    ->pluck('source_purchase_order_item_piece_id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                $entry = $consumed[$sourceId] ?? ['quantity' => 0.0, 'total_weight' => 0.0, 'piece_ids' => [], 'whole_line' => false];

                $consumed[$sourceId] = [
                    'quantity' => $entry['quantity'] + (float) $item->quantity,
                    'total_weight' => $entry['total_weight'] + (float) $item->total_weight,
                    'piece_ids' => array_values(array_unique([...$entry['piece_ids'], ...$pieceIds])),
                    'whole_line' => $entry['whole_line'] || $pieceIds === [],
                ];
            });

        return $consumed;
    }

    /**
     * الاصناف الفرعية (قطع البند الموزون) التي لم تُسحب بعد في طلبية تجميعية اخرى.
     *
     * @param  array<int, array{quantity: float, total_weight: float, piece_ids: array<int, int>, whole_line: bool}>  $consumed
     * @return array<int, int>
     */
    public function availablePieceIds(PurchaseOrderItem $item, array $consumed): array
    {
        $taken = $consumed[$item->id] ?? null;

        if ($taken['whole_line'] ?? false) {
            return [];
        }

        return $item->pieces
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->diff($taken['piece_ids'] ?? [])
            ->values()
            ->all();
    }

    /**
     * بند طلبية الشراء مقتصراً على اصنافه الفرعية المختارة، ليُبنى منه بند الطلبية التجميعية
     * ورأسه كما سيظهران.
     *
     * الوزن الفعلي من الميزان يخص الصنف الرئيسي باصنافه كلها، فلا يُنقل اذا سُحب بعضها؛
     * يُسعَّر عندها بالوزن الكلي للاصناف المختارة.
     *
     * @param  array<int, int>  $pieceIds
     */
    public static function withPieces(PurchaseOrderItem $item, array $pieceIds): PurchaseOrderItem
    {
        $pieceIds = array_map(fn ($id): int => (int) $id, $pieceIds);
        $pieces = $item->pieces->filter(fn ($piece): bool => in_array((int) $piece->id, $pieceIds, true))->values();

        if ($pieces->count() === $item->pieces->count()) {
            return $item;
        }

        $partial = clone $item;
        $partial->setRelation('pieces', $pieces);
        $partial->total_weight = round($pieces->sum(fn ($piece): float => (float) $piece->total_weight), 4);
        $partial->actual_weight = null;

        return $partial;
    }

    /**
     * الكمية الاصلية للبند كما هي في طلبية الشراء: بالوزن للموزون وبالعدد لغيره.
     */
    public static function ordered(PurchaseOrderItem $item): float
    {
        return Number::normalizeQuantity(self::isWeightPriced($item) ? $item->total_weight : $item->quantity);
    }

    /**
     * الكمية المتبقية من البند: بالوزن للاصناف الموزونة وبالعدد لغيرها.
     *
     * التقريب على ثلاث خانات لا اربع: حقول الادخال كلها بخطوة 0.001، فالخانة الرابعة لا
     * يمكن للمستخدم كتابتها أصلاً. تركها كانت تُظهر متبقياً مثل 29.9999 لبند كميته 30.
     *
     * @param  array<int, array{quantity: float, total_weight: float}>  $consumed
     */
    public static function remaining(PurchaseOrderItem $item, array $consumed): float
    {
        $isWeightPriced = self::isWeightPriced($item);
        $key = $isWeightPriced ? 'total_weight' : 'quantity';

        $ordered = (float) ($isWeightPriced ? $item->total_weight : $item->quantity);
        $taken = (float) ($consumed[$item->id][$key] ?? 0);

        return Number::normalizeQuantity($ordered - $taken);
    }

    /**
     * هل ما زال البند متاحاً للسحب؟
     *
     * البند الموزون متاح ما دام فيه صنف فرعي لم يُسحب، والبند الموزون بلا اصناف فرعية
     * يُسحب كاملاً مرة واحدة فيبقى متاحاً ما لم يُسحب، حتى لو كان وزنه الكلي صفراً.
     * غير الموزون متاح ما دام فيه متبقٍّ.
     *
     * @param  array<int, array{quantity: float, total_weight: float, piece_ids: array<int, int>, whole_line: bool}>  $consumed
     */
    public function isAvailable(PurchaseOrderItem $item, array $consumed): bool
    {
        if (self::isWeightPriced($item)) {
            return $item->pieces->isEmpty()
                ? ! array_key_exists((int) $item->id, $consumed)
                : $this->availablePieceIds($item, $consumed) !== [];
        }

        return self::remaining($item, $consumed) > 0;
    }

    /**
     * هل البند مسعّر بالوزن؟ الموزون يُسحب بالكامل، وغيره يقبل كمية جزئية.
     */
    public static function isWeightPriced(PurchaseOrderItem $item): bool
    {
        return $item->isWeightPricedLine();
    }

    /**
     * الكمية المطلوب سحبها من البند بعد التحقق من المتبقي. البنود الموزونة تُسحب بكامل
     * كميتها لان وزنها مشتق من اصنافها الفرعية المختارة لا من الكمية.
     *
     * @throws DomainException اذا كانت الكمية خارج المدى المسموح.
     */
    public function resolveRequestedQuantity(PurchaseOrderItem $item, mixed $requested): float
    {
        $consumed = $this->consumed([(int) $item->id]);

        if (! $this->isAvailable($item, $consumed)) {
            throw new DomainException(__('This item is fully consolidated.'));
        }

        if (self::isWeightPriced($item)) {
            return (float) $item->quantity;
        }

        $remaining = self::remaining($item, $consumed);

        if ($requested === null || $requested === '') {
            return $remaining;
        }

        $quantity = Number::normalizeQuantity($requested);

        if ($quantity <= 0) {
            throw new DomainException(__('Quantity must be greater than zero.'));
        }

        if ($quantity > $remaining) {
            throw new DomainException(__('Quantity exceeds the remaining amount for this item.'));
        }

        return $quantity;
    }

    /**
     * الحد الاعلى المسموح لكل صف، مفهرس بترتيب الصف. الصفوف الموزونة لا يُعدَّل عددها لان
     * وزنها مشتق من تفصيل القطع.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, float>
     */
    public function quantityLimitsForRows(array $rows): array
    {
        $sourceIds = collect($rows)
            ->pluck('source_purchase_order_item_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($sourceIds->isEmpty()) {
            return [];
        }

        $sourceItems = PurchaseOrderItem::query()
            ->whereIn('id', $sourceIds->all())
            ->with('product:id,pricing_type')
            ->get()
            ->keyBy('id');

        $consumed = $this->consumed($sourceIds->all());
        $limits = [];

        foreach ($rows as $index => $row) {
            $sourceItem = $sourceItems->get((int) ($row['source_purchase_order_item_id'] ?? 0));

            if (! $sourceItem || self::isWeightPriced($sourceItem)) {
                continue;
            }

            $limits[$index] = self::remaining($sourceItem, $consumed);
        }

        return $limits;
    }

    /**
     * ضبط كمية صف موجود ضمن المتبقي من بند طلبية الشراء.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>> الصفوف بعد التعديل
     *
     * @throws DomainException اذا كانت الكمية خارج المدى المسموح.
     */
    public function applyRowQuantity(array $rows, int $index, mixed $requested): array
    {
        if (! array_key_exists($index, $rows)) {
            throw new DomainException(__('This item was not found.'));
        }

        if (($rows[$index]['pricing_type'] ?? 'simple') === 'weight') {
            throw new DomainException(__('Weight-priced items cannot be adjusted here.'));
        }

        $max = $this->quantityLimitsForRows($rows)[$index] ?? null;
        $quantity = Number::normalizeQuantity($requested);

        if ($quantity <= 0) {
            throw new DomainException(__('Quantity must be greater than zero.'));
        }

        if ($max !== null && $quantity > $max) {
            throw new DomainException(__('Quantity exceeds the remaining amount for this item.'));
        }

        $rows[$index]['quantity'] = $quantity;

        return $rows;
    }

    /**
     * يتحقق من كميات كل الصفوف قبل الحفظ: صف كمية فوق المتبقي من بنده يُرفض.
     *
     * @param  array<int, array<string, mixed>>  $rows
     *
     * @throws DomainException
     */
    public function assertRowQuantities(array $rows): void
    {
        // بنود المصدر مقفلة حتى نهاية المعاملة: صفحتا اضافة مفتوحتان معاً لا تسحبان القطع نفسها.
        $sourceIds = collect($rows)->pluck('source_purchase_order_item_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $sources = PurchaseOrderItem::query()->with(['pieces', 'product'])->whereKey($sourceIds)->lockForUpdate()->get()->keyBy('id');
        $consumed = $this->consumed($sourceIds);

        foreach (array_keys($rows) as $index) {
            $source = $sources->get((int) ($rows[$index]['source_purchase_order_item_id'] ?? 0));

            if (! $source) {
                throw new DomainException(__('This item was not found.'));
            }

            // نوع البند من المصدر نفسه، لا مما ارسلته الصفحة.
            if (self::isWeightPriced($source)) {
                $this->assertPiecesAvailable($source, $rows[$index], $consumed);

                continue;
            }

            $rows[$index]['pricing_type'] = 'simple';
            $this->applyRowQuantity($rows, $index, $rows[$index]['quantity'] ?? null);
        }
    }

    /**
     * قطع بند الوزن في الصف ما زالت متاحة: لم تُسحب في طلبية تجميعية اخرى. صف بلا قطع يعني
     * البند كله.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, array{quantity: float, total_weight: float, piece_ids: array<int, int>, whole_line: bool}>  $consumed
     *
     * @throws DomainException
     */
    private function assertPiecesAvailable(PurchaseOrderItem $source, array $row, array $consumed): void
    {
        if ($source->pieces->isEmpty()) {
            if (array_key_exists((int) $source->id, $consumed)) {
                throw new DomainException(__('This item was already added to another consolidated purchase order.'));
            }

            return;
        }

        $requested = collect($row['pieces'] ?? [])->pluck('source_purchase_order_item_piece_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $requested = $requested === [] ? $source->pieces->pluck('id')->map(fn ($id): int => (int) $id)->all() : $requested;

        if (array_diff($requested, $this->availablePieceIds($source, $consumed)) !== []) {
            throw new DomainException(__('This item was already added to another consolidated purchase order.'));
        }
    }

    /**
     * صف البند كما يُحفظ في الطلبية التجميعية.
     *
     * @return array<string, mixed>
     */
    public static function payloadFor(PurchaseOrderItem $item, float $quantity): array
    {
        $isWeightPriced = self::isWeightPriced($item);

        return [
            'source_purchase_order_item_id' => $item->id,
            'product_id' => $item->product_id,
            'category_finish_id' => $item->category_finish_id,
            'description' => $item->description,
            'quantity' => $quantity,
            'unit_price' => $item->unit_price,
            'actual_weight' => $item->actual_weight,
            'total_weight' => $item->total_weight,
            'pricing_type' => $isWeightPriced ? 'weight' : 'simple',
            'source_purchase_order_id' => $item->purchase_order_id,
            'purchase_order_reference' => $item->purchaseOrder?->reference_no,
            // تفصيل القطع يخص الاصناف الموزونة فقط، وكل قطعة تحفظ مصدرها ليُعرف ما سُحب
            // من اصناف البند الفرعية.
            'pieces' => $isWeightPriced
                ? $item->pieces->values()->map(fn ($piece) => [
                    'source_purchase_order_item_piece_id' => $piece->id,
                    'product_id' => $piece->product_id,
                    // الصنف الفرعي كما سُجّل في طلبية الشراء، لا كما هو في تعريف الصنف الآن.
                    'barcode' => $piece->barcode,
                    'description' => $piece->description,
                    'piece_count' => $piece->piece_count,
                    'piece_length' => $piece->piece_length,
                    'weight_per_meter' => $piece->weight_per_meter,
                ])->toArray()
                : [],
        ];
    }

    /**
     * معرّفات طلبيات الشراء التي استُهلكت كامل كمياتها.
     *
     * @return array<int, int>
     */
    public function fullyConsumedPurchaseOrderIds(): array
    {
        $consumedItemIds = $this->activeItems()
            ->whereNotNull('source_purchase_order_item_id')
            ->distinct()
            ->pluck('source_purchase_order_item_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($consumedItemIds === []) {
            return [];
        }

        // فقط طلبيات الشراء التي سُحب منها سابقاً مرشّحة لان تكون مستهلكة بالكامل.
        $candidateOrderIds = PurchaseOrderItem::query()
            ->whereIn('id', $consumedItemIds)
            ->distinct()
            ->pluck('purchase_order_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($candidateOrderIds === []) {
            return [];
        }

        $items = PurchaseOrderItem::query()
            ->whereIn('purchase_order_id', $candidateOrderIds)
            ->with(['product:id,pricing_type', 'pieces:id,purchase_order_item_id'])
            ->get();

        $consumed = $this->consumed($items->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $items
            ->groupBy('purchase_order_id')
            ->filter(fn (Collection $orderItems) => $orderItems->every(fn (PurchaseOrderItem $item) => ! $this->isAvailable($item, $consumed)))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * بنود الطلبيات التجميعية التي تُحتسب مسحوبة: الطلبية الملغاة عبر "تغيير الحالة" تبقى
     * بنودها، فلا تُحسب؛ والطلبية الحالية عند التعديل لا تُحسب.
     *
     * @return \Illuminate\Database\Eloquent\Builder<ConsolidatedPurchaseOrderItem>
     */
    private function activeItems()
    {
        return ConsolidatedPurchaseOrderItem::query()
            ->whereHas('consolidatedPurchaseOrder', fn ($query) => $query->where('status', '!=', ConsolidatedPurchaseOrderStatus::CANCELLED->value()))
            ->when($this->excludedOrderId, fn ($query, $id) => $query->where('consolidated_purchase_order_id', '!=', $id));
    }
}
