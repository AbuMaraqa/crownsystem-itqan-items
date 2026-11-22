<?php

namespace Modules\Accounting\Services;

use DomainException;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderItem;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Accounting\Entities\PurchaseOrderItemPiece;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Support\Number;
use Modules\Accounting\Support\PurchaseOrderConsumption;
use Modules\Accounting\Support\PurchaseOrderMainItems;

/**
 * نافذة "اضافة اصناف من طلبية شراء" في الطلبية التجميعية، في صفحة الاضافة (الصفوف في
 * النموذج حتى الحفظ) وفي تبويب الاصناف بصفحة التعديل (كل سحب يُحفظ فوراً).
 *
 * كل بند في طلبية الشراء صنف رئيسي وتحته اصنافه الفرعية، والمستخدم يؤشّر الاصناف الفرعية
 * نفسها لا الصنف الرئيسي كاملاً. ما يُختار من صنف رئيسي واحد يُجمع تحته في بند واحد ولو
 * أُضيف على دفعات، والصنف الفرعي المسحوب في طلبية تجميعية اخرى لا يظهر. صنف الكمية (بلا
 * اصناف فرعية) يُضاف بكميته.
 *
 * "المسحوب" ($pulled): بنود طلبية الشراء المضافة لهذه الطلبية => مصادر اصنافها الفرعية؛
 * البند المضاف بلا قطع لها مصدر يقابله [] (أُضيف كاملاً).
 */
class ConsolidatedPurchaseOrderPickerService
{
    public function __construct(private readonly ConsolidatedPurchaseOrderService $orders) {}

    /**
     * طلبيات الشراء المتاحة للسحب منها: غير المفوترة او الملغاة، وغير المستهلكة بالكامل.
     *
     * @return list<array{value: int, label: string}>
     */
    public function purchaseOrderOptions(?string $search = null, ?int $excludedOrderId = null): array
    {
        $search = trim((string) $search);

        return PurchaseOrder::query()
            ->with('client')
            ->whereNotIn('status', [PurchaseOrderStatus::INVOICED->value(), PurchaseOrderStatus::CANCELLED->value()])
            ->whereNotIn('id', PurchaseOrderConsumption::for($excludedOrderId)->fullyConsumedPurchaseOrderIds())
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('reference_no', 'like', "%{$search}%")
                ->orWhereHas('client', fn ($query) => $query->where('name', 'like', "%{$search}%"))))
            ->latest('order_date')
            ->limit(100)
            ->get()
            ->map(fn (PurchaseOrder $order): array => ['value' => (int) $order->id, 'label' => self::orderLabel($order)])
            ->values()
            ->all();
    }

    public function purchaseOrderLabel(?int $id): ?string
    {
        $order = $id ? PurchaseOrder::query()->with('client')->find($id) : null;

        return $order ? self::orderLabel($order) : null;
    }

    /**
     * المسحوب من صفوف صفحة الاضافة (قبل الحفظ).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<int, int>>
     */
    public function pulledFromRows(array $rows): array
    {
        return collect($rows)
            ->filter(fn ($row): bool => is_array($row) && filled($row['source_purchase_order_item_id'] ?? null))
            ->mapWithKeys(fn (array $row): array => [
                (int) $row['source_purchase_order_item_id'] => collect($row['pieces'] ?? [])
                    ->pluck('source_purchase_order_item_piece_id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * المسحوب في طلبية محفوظة.
     *
     * @return array<int, array<int, int>>
     */
    public function pulledFromOrder(ConsolidatedPurchaseOrder $order): array
    {
        return $order->items()
            ->whereNotNull('source_purchase_order_item_id')
            ->with('pieces:id,consolidated_purchase_order_item_id,source_purchase_order_item_piece_id')
            ->get(['id', 'source_purchase_order_item_id'])
            ->groupBy('source_purchase_order_item_id')
            ->map(function ($items): array {
                $pieceIds = $items->map(fn (ConsolidatedPurchaseOrderItem $item): array => $item->pieces
                    ->pluck('source_purchase_order_item_piece_id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->all());

                // بند سُحب كاملاً قبل حفظ مصدر القطع يعني ان البند كله مضاف.
                return $pieceIds->contains(fn (array $ids): bool => $ids === [])
                    ? []
                    : $pieceIds->flatten()->unique()->values()->all();
            })
            ->all();
    }

    /**
     * بنود طلبية الشراء كما في شاشة النظام القديم: كل بند صنف رئيسي برأسه، وتحته جدول
     * اصنافه الفرعية للتأشير. البحث بصنف فرعي يُظهر صنفه الرئيسي ويميّز الصنف المطابق.
     *
     * @param  array<int, array<int, int>>  $pulled
     * @param  array<int, string>  $selected  المفاتيح المؤشَّرة الآن؛ تبقى ظاهرة ولو اخفاها البحث
     * @return array<int, array<string, mixed>>
     */
    public function mainItems(int $purchaseOrderId, array $pulled, ?int $excludedOrderId = null, ?string $search = '', mixed $length = null, mixed $quantity = null, array $selected = []): array
    {
        $consumption = PurchaseOrderConsumption::for($excludedOrderId);

        $items = PurchaseOrderItem::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->with(['product', 'pieces'])
            ->orderBy('id')
            ->get();

        $consumed = $consumption->consumed($items->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $selected = array_map('strval', $selected);

        // البنود المستهلكة بالكامل لا تظهر، الا اذا كانت مضافة في هذه الطلبية.
        $items = $items
            ->filter(fn (PurchaseOrderItem $item): bool => array_key_exists((int) $item->id, $pulled) || $consumption->isAvailable($item, $consumed))
            ->values();

        // الاصناف الفرعية المسحوبة في طلبية تجميعية اخرى لا تظهر.
        $lines = $items->mapWithKeys(fn (PurchaseOrderItem $item): array => [
            $item->id => self::hasSubItemPieces($item)
                ? PurchaseOrderConsumption::withPieces($item, [...$consumption->availablePieceIds($item, $consumed), ...self::pulledPieceIdsOf($item, $pulled)])
                : $item,
        ]);

        $mainItems = PurchaseOrderMainItems::for($lines->values());

        return $items
            ->map(fn (PurchaseOrderItem $item): ?array => $this->mainItemRow($item, $lines[$item->id], $mainItems[$item->id], $pulled, $consumed, $selected, (string) $search, $length, $quantity))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * سحب صنف بلا اصناف فرعية (صنف الكمية، او صنف الوزن الذي لا قطع فيه) بالكمية المكتوبة.
     *
     * @param  array<int, array<int, int>>  $pulled
     * @param  callable(PurchaseOrderItem, float, array<int, int>|null): void  $store
     *
     * @throws DomainException
     */
    public function pullItem(int $purchaseOrderItemId, mixed $quantity, array $pulled, ?int $excludedOrderId, callable $store): void
    {
        $item = PurchaseOrderItem::query()->with(['pieces', 'product', 'purchaseOrder'])->find($purchaseOrderItemId);

        if (! $item) {
            throw new DomainException(__('This item was not found.'));
        }

        $this->pullLine($item, $pulled, $excludedOrderId, $store, null, $quantity);
    }

    /**
     * سحب الاصناف الفرعية المؤشَّرة، كلٌّ تحت صنفه الرئيسي: piece-{id} لصنف فرعي داخل بند
     * الوزن، و line-{id} لبند هو نفسه صنف فرعي.
     *
     * @param  array<int, string>  $keys
     * @param  array<int, array<int, int>>  $pulled
     * @param  callable(PurchaseOrderItem, float, array<int, int>|null): void  $store
     * @return array{added: int, skipped: int}
     *
     * @throws DomainException اذا لم يُؤشَّر شيء.
     */
    public function pullSelected(array $keys, array $pulled, ?int $excludedOrderId, callable $store): array
    {
        $keys = array_map('strval', $keys);
        $pieceIds = self::idsWithPrefix($keys, 'piece-');
        $lineIds = self::idsWithPrefix($keys, 'line-');

        if ($pieceIds === [] && $lineIds === []) {
            throw new DomainException(__('Please select at least one sub-item.'));
        }

        $piecesByLine = PurchaseOrderItemPiece::query()
            ->whereIn('id', $pieceIds)
            ->get(['id', 'purchase_order_item_id'])
            ->groupBy('purchase_order_item_id')
            ->map(fn ($pieces): array => $pieces->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $lines = PurchaseOrderItem::query()
            ->whereIn('id', [...$piecesByLine->keys()->all(), ...$lineIds])
            ->with(['pieces', 'product', 'purchaseOrder'])
            ->orderBy('id')
            ->get();

        $added = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            try {
                $added += $this->pullLine($line, $pulled, $excludedOrderId, $store, $piecesByLine->get($line->id));
            } catch (DomainException) {
                $skipped++;
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * صف صفحة الاضافة: صف البند كما يُحفظ، ومعه للعرض الصنف الرئيسي واصنافه الفرعية
     * المختارة، والحد الاعلى لكميته (صنف الكمية).
     *
     * @param  array<int, int>|null  $pieceIds
     * @return array<string, mixed>
     */
    public function rowFor(PurchaseOrderItem $line, float $quantity, ?array $pieceIds): array
    {
        $line = $pieceIds === null ? $line : PurchaseOrderConsumption::withPieces($line, $pieceIds);
        $isWeight = PurchaseOrderConsumption::isWeightPriced($line);

        return array_merge(PurchaseOrderConsumption::payloadFor($line, $quantity), [
            'main_item' => PurchaseOrderMainItems::for([$line])[$line->id],
            'limit' => $isWeight ? null : PurchaseOrderConsumption::remaining($line, PurchaseOrderConsumption::for()->consumed([(int) $line->id])),
        ]);
    }

    /**
     * ما يُختار لاحقاً من نفس الصنف الرئيسي يحلّ محل صفه ليبقى تحته.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $row
     * @return array<int, array<string, mixed>>
     */
    public function upsertRow(array $rows, array $row): array
    {
        $rows = array_values($rows);
        $index = collect($rows)->search(fn ($item): bool => (int) ($item['source_purchase_order_item_id'] ?? 0) === (int) $row['source_purchase_order_item_id']);

        if ($index === false) {
            $rows[] = $row;
        } else {
            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * سحب في طلبية محفوظة: الاصناف الفرعية المختارة من صنف رئيسي مضاف سابقاً تُضم الى بنده
     * نفسه، فيبقى بند واحد ويبقى ما ارتبط به (تكاليف اضافية، اعادة تخمين) مع سعره وخصمه.
     *
     * @param  array<int, int>|null  $pieceIds  كل اصناف البند الفرعية في الطلبية، او null للبند بلا اصناف فرعية
     */
    public function storeInOrder(ConsolidatedPurchaseOrder $order, PurchaseOrderItem $line, float $quantity, ?array $pieceIds): void
    {
        $existing = $pieceIds === null
            ? null
            : $order->items()->where('source_purchase_order_item_id', $line->id)->with('pieces')->first();

        $row = PurchaseOrderConsumption::payloadFor($pieceIds === null ? $line : PurchaseOrderConsumption::withPieces($line, $pieceIds), $quantity);

        if ($existing) {
            $existingPieces = $existing->pieces->keyBy('source_purchase_order_item_piece_id');
            $pieces = collect($row['pieces'])->map(fn (array $piece): array => array_merge($piece, [
                'id' => $existingPieces->get($piece['source_purchase_order_item_piece_id'])?->id,
            ]));
            $current = ConsolidatedPurchaseOrderService::itemRow($existing, (float) $existing->quantity);

            // قطعة حُذف مصدرها من طلبية الشراء تبقى كما هي.
            $orphans = collect($current['pieces'])->reject(fn (array $piece): bool => $pieces->pluck('id')->contains($piece['id']));

            $row = array_merge($current, [
                'actual_weight' => $row['actual_weight'],
                'pieces' => [...$pieces->all(), ...$orphans->values()->all()],
            ]);
        }

        $this->orders->storeItem($order, $row);
    }

    /**
     * سحب في طلبية محفوظة بنفس قواعد النافذة.
     *
     * @param  array<int, string>  $keys
     * @return array{added: int, skipped: int}
     */
    public function pullIntoOrder(ConsolidatedPurchaseOrder $order, array $keys = [], ?int $purchaseOrderItemId = null, mixed $quantity = null): array
    {
        $store = fn (PurchaseOrderItem $line, float $qty, ?array $pieceIds) => $this->storeInOrder($order, $line, $qty, $pieceIds);

        if ($purchaseOrderItemId !== null) {
            $this->pullItem($purchaseOrderItemId, $quantity, $this->pulledFromOrder($order), $order->id, $store);

            return ['added' => 1, 'skipped' => 0];
        }

        return $this->pullSelected($keys, $this->pulledFromOrder($order), $order->id, $store);
    }

    /**
     * سحب في صفوف صفحة الاضافة: تعود الصفوف بعد الاضافة.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $keys
     * @return array{rows: array<int, array<string, mixed>>, added: int, skipped: int}
     */
    public function pullIntoRows(array $rows, array $keys = [], ?int $purchaseOrderItemId = null, mixed $quantity = null): array
    {
        $pulled = $this->pulledFromRows($rows);
        $store = function (PurchaseOrderItem $line, float $qty, ?array $pieceIds) use (&$rows): void {
            $rows = $this->upsertRow($rows, $this->rowFor($line, $qty, $pieceIds));
        };

        if ($purchaseOrderItemId !== null) {
            $this->pullItem($purchaseOrderItemId, $quantity, $pulled, null, $store);

            return ['rows' => array_values($rows), 'added' => 1, 'skipped' => 0];
        }

        $result = $this->pullSelected($keys, $pulled, null, $store);

        return ['rows' => array_values($rows), ...$result];
    }

    /**
     * المتبقي من بند طلبية الشراء المصدر، او null اذا لم يكن للبند مصدر او كان موزوناً.
     */
    public function remainingFor(ConsolidatedPurchaseOrderItem $item): ?float
    {
        $sourceId = (int) $item->source_purchase_order_item_id;
        $sourceItem = $sourceId > 0 ? PurchaseOrderItem::query()->with('product:id,pricing_type')->find($sourceId) : null;

        if (! $sourceItem || PurchaseOrderConsumption::isWeightPriced($sourceItem)) {
            return null;
        }

        $consumption = PurchaseOrderConsumption::for((int) $item->consolidated_purchase_order_id);

        return PurchaseOrderConsumption::remaining($sourceItem, $consumption->consumed([$sourceId]));
    }

    /**
     * كمية بند محفوظ ضمن المتبقي من بند طلبية الشراء. الاصناف الموزونة تُسحب بكامل كميتها.
     *
     * @throws DomainException
     */
    public function updateQuantity(ConsolidatedPurchaseOrderItem $item, mixed $quantity): ConsolidatedPurchaseOrderItem
    {
        $item->loadMissing(['product', 'pieces', 'consolidatedPurchaseOrder']);

        if ($item->isWeightPricedLine()) {
            throw new DomainException(__('Weight-priced items cannot be adjusted here.'));
        }

        $remaining = $this->remainingFor($item);
        $quantity = round((float) $quantity, 4);

        if ($quantity <= 0) {
            throw new DomainException(__('Quantity must be greater than zero.'));
        }

        if ($remaining !== null && $quantity > $remaining) {
            throw new DomainException(__('Quantity exceeds the remaining amount for this item.'));
        }

        return $this->orders->storeItem($item->consolidatedPurchaseOrder, ConsolidatedPurchaseOrderService::itemRow($item, $quantity));
    }

    /**
     * سحب البند او بعض اصنافه الفرعية، مع ضمها لما أُضيف منه سابقاً.
     *
     * @param  array<int, array<int, int>>  $pulled
     * @param  array<int, int>|null  $requestedPieceIds  null لكل الاصناف الفرعية المتاحة
     * @return int عدد ما أُضيف (اصنافاً فرعية، او 1 للبند بلا اصناف فرعية)
     *
     * @throws DomainException اذا لم يبق ما يُضاف.
     */
    private function pullLine(PurchaseOrderItem $item, array $pulled, ?int $excludedOrderId, callable $store, ?array $requestedPieceIds = null, mixed $requestedQuantity = null): int
    {
        $consumption = PurchaseOrderConsumption::for($excludedOrderId);
        $isPulled = array_key_exists((int) $item->id, $pulled);

        if (! self::hasSubItemPieces($item)) {
            if ($isPulled) {
                throw new DomainException(__('This item was already added.'));
            }

            $store($item, $consumption->resolveRequestedQuantity($item, $requestedQuantity), null);

            return 1;
        }

        $pulledPieceIds = self::pulledPieceIdsOf($item, $pulled);
        $available = $consumption->availablePieceIds($item, $consumption->consumed([(int) $item->id]));
        $newPieceIds = array_values(array_diff(array_intersect($requestedPieceIds ?? $available, $available), $pulledPieceIds));

        if ($newPieceIds === []) {
            throw new DomainException(__('This item was already added.'));
        }

        $store($item, (float) $item->quantity, [...$pulledPieceIds, ...$newPieceIds]);

        return count($newPieceIds);
    }

    /**
     * @param  array<string, mixed>  $main  من PurchaseOrderMainItems::for()
     * @param  array<int, array<int, int>>  $pulled
     * @param  array<int, array<string, mixed>>  $consumed
     * @param  array<int, string>  $selected
     * @return array<string, mixed>|null
     */
    private function mainItemRow(PurchaseOrderItem $item, PurchaseOrderItem $line, array $main, array $pulled, array $consumed, array $selected, string $search, mixed $length, mixed $quantity): ?array
    {
        $isPulled = array_key_exists((int) $item->id, $pulled);
        $keys = [];

        if (self::hasSubItemPieces($item)) {
            $pulledPieceIds = self::pulledPieceIdsOf($item, $pulled);

            $keys = $line->pieces->values()->map(fn ($piece): array => [
                'key' => 'piece-'.$piece->id,
                'added' => in_array((int) $piece->id, $pulledPieceIds, true),
            ])->all();
        }

        // بند صنفه فرعي أُدخل وحده: الصنف الفرعي الوحيد تحت رأسه هو البند نفسه.
        if ($keys === [] && $main['sub_items'] !== []) {
            $keys = [['key' => 'line-'.$item->id, 'added' => $isPulled]];
        }

        $selectableKeys = collect($keys)->reject(fn (array $key): bool => $key['added'])->pluck('key')->values()->all();
        $result = PurchaseOrderMainItems::search($main, $search, $length, $quantity);

        // ما أُشّر يبقى ظاهراً حتى لا يُضاف صنف اخفاه البحث عن المستخدم.
        if (! $result['visible'] && array_intersect($selectableKeys, $selected) === []) {
            return null;
        }

        $isWeightPriced = PurchaseOrderConsumption::isWeightPriced($item);
        $remaining = PurchaseOrderConsumption::remaining($item, $consumed);

        return [
            'id' => (int) $item->id,
            'measure' => ($isWeightPriced ? __('Original Weight') : __('Original Quantity'))
                .': '.Number::quantity(PurchaseOrderConsumption::ordered($item))
                .' — '.($isWeightPriced ? __('Remaining Weight') : __('Remaining Quantity'))
                .': '.Number::quantity($remaining),
            'remaining' => $remaining,
            'is_weight_priced' => $isWeightPriced,
            'main' => $main,
            'matched' => $result['matched'],
            // صنف له اصناف فرعية تُؤشَّر اصنافه، وغيره يُضاف كاملاً بزر الاضافة.
            'picks_sub_items' => $keys !== [],
            'sub_item_keys' => $keys,
            'selectable_keys' => $selectableKeys,
            'already_added' => $keys !== [] ? $selectableKeys === [] : $isPulled,
        ];
    }

    /**
     * بند وزن فيه اصناف فرعية (قطع) تُختار كلٌّ وحدها.
     */
    private static function hasSubItemPieces(PurchaseOrderItem $item): bool
    {
        return PurchaseOrderConsumption::isWeightPriced($item) && $item->pieces->isNotEmpty();
    }

    /**
     * الاصناف الفرعية للبند المضافة لهذه الطلبية؛ البند المضاف قبل حفظ مصدر القطع مضاف
     * باصنافه كلها.
     *
     * @param  array<int, array<int, int>>  $pulled
     * @return array<int, int>
     */
    private static function pulledPieceIdsOf(PurchaseOrderItem $item, array $pulled): array
    {
        if (! array_key_exists((int) $item->id, $pulled)) {
            return [];
        }

        return $pulled[$item->id] !== []
            ? $pulled[$item->id]
            : $item->pieces->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, int>
     */
    private static function idsWithPrefix(array $keys, string $prefix): array
    {
        return collect($keys)
            ->filter(fn (string $key): bool => str_starts_with($key, $prefix))
            ->map(fn (string $key): int => (int) substr($key, strlen($prefix)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function orderLabel(PurchaseOrder $order): string
    {
        return $order->reference_no.' — '.$order->client?->name;
    }
}
