<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderItem;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Enums\ConsolidatedPurchaseOrderStatus;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Items\Entities\Product;

class ConsolidatedPurchaseOrderService
{
    public function create(array $data): ConsolidatedPurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $items = $this->normalizeItems($data['items'] ?? []);
            $data = $this->normalizeData($data);
            $data = array_merge($data, $this->calculateTotals($items, $data['order_discount'], $data['tax_rate']));
            $data['reference_no'] = app(ReferenceNumberService::class)->generate(
                'consolidated_purchase_order',
                $data['order_date'],
                ['prefix' => 'CPORD']
            );
            $data['status'] = ConsolidatedPurchaseOrderStatus::UNCONFIRMED->value();
            $data['created_by'] = $data['created_by'] ?? auth()->id();

            $order = ConsolidatedPurchaseOrder::create($data);
            $this->syncItems($order, $items);

            return $order->refresh()->load(['client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces.product']);
        });
    }

    public function update(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $items = $this->normalizeItems($data['items'] ?? []);
            $data = $this->normalizeData($data);
            unset($data['reference_no'], $data['created_by']);

            $data = array_merge($data, $this->calculateTotals($items, $data['order_discount'], $data['tax_rate']));

            $order->update($data);
            $this->syncItems($order, $items);

            return $order->refresh()->load(['client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces.product']);
        });
    }

    /**
     * حفظ رأس الطلبية من صفحة التعديل (التاريخان والمتابِع والملاحظات). الطرف والمستودع
     * والعملة والضريبة والخصم تُحدَّد عند الانشاء ولا تُعدَّل هنا فتُحمل كما هي، والبنود
     * يديرها تبويب الاصناف ويحفظها فور تعديلها، فتُقرأ من قاعدة البيانات حتى لا يمسح
     * الحفظ ما اضافه التبويب ولا مصدر القطع وباركودها ووصفها كما سُحبت.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrder
    {
        return $this->update($order, [
            'order_date' => $data['order_date'] ?? $order->order_date?->toDateString(),
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'followed_up_by' => $data['followed_up_by'] ?? null,
            'notes' => $data['notes'] ?? null,
            'client_id' => $order->client_id,
            'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id,
            'currency_id' => $order->currency_id,
            'tax_id' => $order->tax_id,
            'order_discount' => $order->order_discount,
            'items' => $this->itemRows($order),
        ]);
    }

    /**
     * بنود الطلبية المحفوظة بالشكل الذي تقرؤه الخدمة.
     *
     * @return array<int, array<string, mixed>>
     */
    public function itemRows(ConsolidatedPurchaseOrder $order): array
    {
        return $order->items()
            ->with('pieces')
            ->get()
            ->map(fn (ConsolidatedPurchaseOrderItem $item): array => self::itemRow($item, (float) $item->quantity))
            ->all();
    }

    /**
     * صف البند بالشكل الذي تتوقعه الخدمة، مع الحفاظ على الخصم وتفصيل القطع كما سُحبت
     * (مصدرها وباركودها ووصفها).
     *
     * @return array<string, mixed>
     */
    public static function itemRow(ConsolidatedPurchaseOrderItem $item, float $quantity): array
    {
        return [
            'id' => $item->id,
            'source_purchase_order_item_id' => $item->source_purchase_order_item_id,
            'product_id' => $item->product_id,
            'barcode' => $item->barcode,
            'description' => $item->description,
            'quantity' => $quantity,
            'unit_price' => $item->unit_price,
            'discount_mode' => 'rate',
            'discount_rate' => $item->discount_rate,
            'actual_weight' => $item->actual_weight,
            'notes' => $item->notes,
            'pieces' => $item->pieces->map(fn ($piece): array => [
                'id' => $piece->id,
                'source_purchase_order_item_piece_id' => $piece->source_purchase_order_item_piece_id,
                'product_id' => $piece->product_id,
                'barcode' => $piece->barcode,
                'description' => $piece->description,
                'piece_count' => $piece->piece_count,
                'piece_length' => $piece->piece_length,
                'weight_per_meter' => $piece->weight_per_meter,
            ])->toArray(),
        ];
    }

    public function changeStatus(ConsolidatedPurchaseOrder $order, ConsolidatedPurchaseOrderStatus $status): ConsolidatedPurchaseOrder
    {
        $order->update(['status' => $status->value()]);

        return $order->refresh();
    }

    public function delete(ConsolidatedPurchaseOrder $order): void
    {
        DB::transaction(function () use ($order) {
            // الطلبية المحذوفة تبقى في المحذوفات بحالة "الحذف والالغاء".
            $order->update(['status' => ConsolidatedPurchaseOrderStatus::CANCELLED->value()]);
            $order->items()->delete();
            $order->delete();
        });
    }

    /**
     * حفظ بند واحد (اضافة او تعديل) مباشرة، لان جدول الاصناف في صفحة التعديل
     * يعمل مثل باقي الجداول: كل عملية تُحفظ فوراً بدل انتظار زر الحفظ.
     *
     * @param  array<string, mixed>  $row
     */
    public function storeItem(ConsolidatedPurchaseOrder $order, array $row): ConsolidatedPurchaseOrderItem
    {
        return DB::transaction(function () use ($order, $row) {
            $normalized = $this->normalizeItems([$row]);

            if ($normalized === []) {
                throw new DomainException(__('This item was not found.'));
            }

            $item = $normalized[0];
            $id = $item['id'] ?? null;
            $pieces = $item['pieces'] ?? [];
            unset($item['id'], $item['pieces']);

            $existingItem = $id ? $order->items()->whereKey($id)->first() : null;

            if ($existingItem) {
                $existingItem->update($item);
            } else {
                $existingItem = $order->items()->create($item);
            }

            $this->syncPieces($existingItem, $pieces);
            $this->recalculateTotals($order);

            return $existingItem->refresh();
        });
    }

    public function deleteItem(ConsolidatedPurchaseOrderItem $item): void
    {
        DB::transaction(function () use ($item) {
            $order = $item->consolidatedPurchaseOrder;

            $item->pieces()->delete();
            $item->delete();

            if ($order) {
                $this->recalculateTotals($order);
            }
        });
    }

    /**
     * اعادة احتساب اجماليات الطلبية من بنودها المخزّنة، بعد اي تعديل مباشر عليها.
     */
    public function recalculateTotals(ConsolidatedPurchaseOrder $order): void
    {
        $items = $order->items()
            ->get()
            ->map(fn (ConsolidatedPurchaseOrderItem $item): array => [
                'line_total' => (float) $item->line_total,
                'discount_amount' => (float) $item->discount_amount,
            ])
            ->all();

        // الخصم المُدخل لا يُستبدل بقيمته المقيَّدة بصافي البنود، وإلا ضاع نهائياً عند حذف بنود ثم اعادتها.
        $order->update(Arr::except($this->calculateTotals(
            $items,
            (float) $order->order_discount,
            (float) $order->tax_rate
        ), ['order_discount']));
    }

    private function normalizeData(array $data): array
    {
        $orderDate = $data['order_date'] ?? now()->toDateString();
        $currencyId = (int) ($data['currency_id'] ?? 0);
        $tax = ! empty($data['tax_id'] ?? null) ? Tax::query()->find($data['tax_id']) : null;
        $exchangeRate = app(CurrencyExchangeRateService::class)->rateFor($currencyId, $orderDate);

        if (! $exchangeRate) {
            throw new DomainException(__('Exchange rate is missing for selected currency and order date.'));
        }

        return [
            'client_id' => filled($data['client_id'] ?? null) ? (int) $data['client_id'] : null,
            'branch_id' => filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null,
            'warehouse_id' => filled($data['warehouse_id'] ?? null) ? (int) $data['warehouse_id'] : null,
            'followed_up_by' => filled($data['followed_up_by'] ?? null) ? (int) $data['followed_up_by'] : null,
            'order_date' => $orderDate,
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'currency_id' => $currencyId,
            'tax_id' => $tax?->id,
            'exchange_rate' => $this->decimalValue($exchangeRate, 1, 8),
            'order_discount' => $this->decimalValue($data['order_discount'] ?? 0),
            'tax_rate' => $this->decimalValue($tax?->rate ?? 0),
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function normalizeItems(array $items): array
    {
        return collect($items)
            ->filter(fn (array $item) => filled($item['product_id'] ?? null))
            ->map(function (array $item): array {
                $product = Product::query()->with('translations')->find($item['product_id']);
                $quantity = $this->decimalValue($item['quantity'] ?? 1, 1);
                $unitPrice = $this->decimalValue($item['unit_price'] ?? ($product ? app(StockLedgerService::class)->averageCost((int) $product->id) : 0));
                $discountMode = ($item['discount_mode'] ?? 'rate') === 'amount' ? 'amount' : 'rate';
                $discountRate = $this->decimalValue($item['discount_rate'] ?? 0);
                $discountAmount = $this->decimalValue($item['discount_amount'] ?? 0);

                if ($quantity <= 0) {
                    throw new DomainException(__('Purchase order item quantity must be greater than zero.'));
                }

                if ($unitPrice < 0) {
                    throw new DomainException(__('Purchase order item price cannot be negative.'));
                }

                if ($discountRate < 0 || $discountRate > 100) {
                    throw new DomainException(__('Purchase order item discount must be between 0 and 100.'));
                }

                $isWeightPriced = $product?->pricing_type === 'weight';
                $pieces = $isWeightPriced ? $this->normalizePieces($item['pieces'] ?? []) : [];
                $totalWeight = round(collect($pieces)->sum('total_weight'), 4);
                $actualWeight = max($this->decimalValue($item['actual_weight'] ?? 0), 0);
                // المبلغ بالوزن الفعلي فقط، وصفر بدونه؛ الوزن الكلي للعرض والمخزون.
                $gross = $isWeightPriced
                    ? round(max($actualWeight, 0) * $unitPrice, 4)
                    : round($quantity * $unitPrice, 4);

                if ($discountMode === 'amount') {
                    if ($discountAmount < 0) {
                        throw new DomainException(__('Purchase order item discount amount cannot be negative.'));
                    }

                    if ($discountAmount > $gross) {
                        throw new DomainException(__('Purchase order item discount amount cannot exceed item total.'));
                    }

                    $discountRate = $gross > 0 ? round(($discountAmount / $gross) * 100, 4) : 0;
                } else {
                    $discountAmount = round($gross * ($discountRate / 100), 4);
                }

                return [
                    'id' => $item['id'] ?? null,
                    'source_purchase_order_item_id' => $item['source_purchase_order_item_id'] ?? null,
                    'product_id' => (int) $item['product_id'],
                    'barcode' => $item['barcode'] ?? $product?->barcode,
                    'description' => $item['description'] ?? $product?->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_rate' => $discountRate,
                    'discount_amount' => $discountAmount,
                    'line_total' => max(round($gross - $discountAmount, 4), 0),
                    'total_weight' => $totalWeight,
                    'actual_weight' => $actualWeight ?: null,
                    'notes' => $item['notes'] ?? null,
                    'created_by' => $item['created_by'] ?? auth()->id(),
                    'pieces' => $pieces,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * @param  array<int, array<string, mixed>>  $pieces
     * @return array<int, array<string, mixed>>
     */
    private function normalizePieces(array $pieces): array
    {
        return collect($pieces)
            ->filter(fn (array $piece) => filled($piece['product_id'] ?? null))
            ->values()
            ->map(function (array $piece, int $index): array {
                $product = Product::query()->with('translations')->find($piece['product_id']);
                $pieceCount = max($this->decimalValue($piece['piece_count'] ?? 1, 1), 0);
                $pieceLength = max($this->decimalValue($piece['piece_length'] ?? 0), 0);
                $weightPerMeter = max($this->decimalValue($piece['weight_per_meter'] ?? $product?->weight_per_meter ?? 0), 0);

                return [
                    'id' => $piece['id'] ?? null,
                    'source_purchase_order_item_piece_id' => filled($piece['source_purchase_order_item_piece_id'] ?? null)
                        ? (int) $piece['source_purchase_order_item_piece_id']
                        : null,
                    'product_id' => (int) $piece['product_id'],
                    'barcode' => $piece['barcode'] ?? $product?->barcode,
                    'description' => $piece['description'] ?? $product?->name,
                    'piece_count' => $pieceCount,
                    'piece_length' => $pieceLength,
                    'weight_per_meter' => $weightPerMeter,
                    'total_weight' => round($pieceCount * $pieceLength * $weightPerMeter, 4),
                    'sort_order' => $index,
                    'created_by' => $piece['created_by'] ?? auth()->id(),
                ];
            })
            ->values()
            ->toArray();
    }

    private function calculateTotals(array $items, float $orderDiscount, float $taxRate): array
    {
        $subTotal = collect($items)->sum(fn (array $item) => round($item['line_total'] + $item['discount_amount'], 4));
        $itemsDiscount = collect($items)->sum('discount_amount');
        $netBeforeDocumentDiscount = max(round($subTotal - $itemsDiscount, 4), 0);
        $orderDiscount = min(max($orderDiscount, 0), $netBeforeDocumentDiscount);
        $taxableTotal = max(round($netBeforeDocumentDiscount - $orderDiscount, 4), 0);
        $taxAmount = round($taxableTotal * ($taxRate / 100), 4);

        return [
            'sub_total' => round($subTotal, 4),
            'items_discount' => round($itemsDiscount, 4),
            'order_discount' => round($orderDiscount, 4),
            'tax_amount' => $taxAmount,
            'total_amount' => round($taxableTotal + $taxAmount, 4),
        ];
    }

    private function syncItems(ConsolidatedPurchaseOrder $order, array $items): void
    {
        $incomingIds = collect($items)->pluck('id')->filter()->values();

        $removedItems = $order->items()
            ->when($incomingIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $incomingIds))
            ->when($incomingIds->isEmpty(), fn ($query) => $query)
            ->get();

        foreach ($removedItems as $removedItem) {
            $removedItem->pieces()->delete();
            $removedItem->delete();
        }

        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $pieces = $item['pieces'] ?? [];
            unset($item['id'], $item['pieces']);

            if ($id && $existingItem = $order->items()->whereKey($id)->first()) {
                $existingItem->update($item);
            } else {
                $existingItem = $order->items()->create($item);
            }

            $this->syncPieces($existingItem, $pieces);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $pieces
     */
    private function syncPieces(ConsolidatedPurchaseOrderItem $item, array $pieces): void
    {
        $incomingIds = collect($pieces)->pluck('id')->filter()->values();

        $item->pieces()
            ->when($incomingIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $incomingIds))
            ->when($incomingIds->isEmpty(), fn ($query) => $query)
            ->delete();

        foreach ($pieces as $piece) {
            $id = $piece['id'] ?? null;
            unset($piece['id']);

            if ($id && $existingPiece = $item->pieces()->whereKey($id)->first()) {
                $existingPiece->update($piece);
            } else {
                $item->pieces()->create($piece);
            }
        }
    }

    private function decimalValue(mixed $value, float $default = 0, int $scale = 4): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        if (! is_numeric($value)) {
            throw new DomainException(__('Invalid numeric value.'));
        }

        return round((float) $value, $scale);
    }
}
