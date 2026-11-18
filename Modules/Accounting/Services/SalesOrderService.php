<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\SalesOrderItem;
use Modules\Accounting\Entities\SalesQuotation;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Enums\SalesOrderFulfillmentType;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Enums\SalesQuotationStatus;
use Modules\Accounting\Support\ItemsGrid;
use Modules\Accounting\Support\ItemsGridState;
use Modules\Accounting\Support\PurchasingNotifier;
use Modules\Accounting\Support\SalesServiceLine;
use Modules\Accounting\Support\WeightPieces;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Items\Entities\Product;
use Modules\Items\Support\ProductSearch;

class SalesOrderService
{
    private const RELATIONS = ['salesQuotation', 'client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces.product', 'items.serviceProvider', 'customerMaterials.product'];

    public function create(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data) {
            $quotation = $this->quotation($data['sales_quotation_id'] ?? null, true);
            $itemsInput = $data['items'] ?? [];

            if ($quotation && ! $quotation->canBeConverted()) {
                throw new DomainException(__('Sales quotation cannot be converted to a sales order.'));
            }

            if ($itemsInput === [] && $quotation) {
                $itemsInput = $this->quotationItemsForOrder($quotation);
            }

            $items = $this->normalizeItems($itemsInput);
            $materials = $this->normalizeCustomerMaterials($data);

            if ($quotation && ($items === [] || (float) $quotation->total_amount <= 0)) {
                throw new DomainException(__('Sales quotation must have at least one item.'));
            }

            $data = $this->normalizeData($data, $quotation);
            $data = array_merge($data, $this->calculateTotals($items, $data['order_discount'], $data['tax_rate']));
            $data['reference_no'] = app(ReferenceNumberService::class)->generate(
                'sales_order',
                $data['order_date'],
                ['prefix' => 'SORD']
            );
            $data['status'] = SalesOrderStatus::DRAFT->value();
            $data['created_by'] = $data['created_by'] ?? auth()->id();

            $order = SalesOrder::create($data);
            $this->syncItems($order, $items);

            if ($materials !== null) {
                $this->syncCustomerMaterials($order, $materials);
            }

            if ($quotation) {
                $quotation->update([
                    'status' => SalesQuotationStatus::CONVERTED->value(),
                    'converted_at' => now(),
                ]);
            }

            return $order->refresh()->load(self::RELATIONS);
        });
    }

    public function createFromQuotation(SalesQuotation $quotation, array $data = []): SalesOrder
    {
        return DB::transaction(function () use ($quotation, $data) {
            $quotation = SalesQuotation::query()
                ->with(['items', 'salesOrders'])
                ->lockForUpdate()
                ->findOrFail($quotation->id);

            if (! $quotation->canBeConverted()) {
                throw new DomainException(__('Sales quotation cannot be converted to a sales order.'));
            }

            if ($quotation->items->isEmpty() || (float) $quotation->total_amount <= 0) {
                throw new DomainException(__('Sales quotation must have at least one item.'));
            }

            $order = $this->create(array_replace([
                'sales_quotation_id' => $quotation->id,
                'client_id' => $quotation->client_id,
                'branch_id' => $quotation->branch_id,
                'warehouse_id' => $quotation->warehouse_id,
                'order_date' => now()->toDateString(),
                'currency_id' => $quotation->currency_id,
                'tax_id' => $quotation->tax_id,
                'order_discount' => $quotation->quotation_discount,
                'notes' => $quotation->notes,
                'items' => $this->quotationItemsForOrder($quotation),
            ], $data));

            return $order;
        });
    }

    /**
     * حفظ نموذج الطلبية من صفحتها (انشاء او تعديل): الصنف الرئيسي الجديد لا يُحفظ بلا اصنافه
     * الفرعية (بنود عرض السعر المنقولة تُترك كما في التحويل)، وبنود قسم الخدمات تُضم الى
     * البنود بعد التحقق انها خدمات.
     *
     * @param  array<string, mixed>  $data  الرأس و items و services و customer_materials
     */
    public function saveForm(?SalesOrder $order, array $data): SalesOrder
    {
        $items = array_values($data['items'] ?? []);

        ItemsGrid::ensureMainItemsHaveSubItems(array_values(array_filter(
            $items,
            fn ($item): bool => is_array($item) && blank($item['sales_quotation_item_id'] ?? null),
        )));

        $data['items'] = SalesServiceLine::merge($items, $data['services'] ?? []);
        unset($data['services']);

        return $order ? $this->update($order, $data) : $this->create($data);
    }

    /**
     * يحفظ تعديلات النموذج (ما دامت الطلبية قابلة للتعديل وأُرسلت) ثم ينفذ الاجراء عليها، في
     * معاملة واحدة: رفض الاجراء لا يُبقي الحفظ نصف منجز. كما كانت ازرار صفحة الطلبية.
     *
     * @template T
     *
     * @param  callable(SalesOrder): T  $action
     * @return T
     */
    public function saveThen(SalesOrder $order, ?array $data, callable $action): mixed
    {
        return DB::transaction(function () use ($order, $data, $action) {
            if ($data !== null && $order->canBeEdited()) {
                $order = $this->saveForm($order, $data);
            }

            return $action($order);
        });
    }

    /**
     * عروض السعر التي تتحول الى طلبية لهذا الزبون: مسودة او معتمدة بلا طلبية.
     *
     * @return list<array{value: int, label: string}>
     */
    public function quotationOptions(mixed $clientId = null, ?string $search = null): array
    {
        return SalesQuotation::query()
            ->when(filled($clientId), fn ($query) => $query->where('client_id', $clientId))
            ->whereIn('status', [SalesQuotationStatus::DRAFT->value(), SalesQuotationStatus::APPROVED->value()])
            ->whereDoesntHave('salesOrders')
            ->when(filled($search), fn ($query) => $query->where('reference_no', 'like', "%{$search}%"))
            ->latest('quotation_date')
            ->limit(50)
            ->get(['id', 'reference_no'])
            ->map(fn (SalesQuotation $quotation): array => ['value' => (int) $quotation->id, 'label' => (string) $quotation->reference_no])
            ->all();
    }

    /**
     * ما يأخذه نموذج الطلبية من عرض السعر المختار: الطرف والمخزن والعملة (بسعر صرف تاريخ
     * الطلبية) والضريبة والخصم، والاصناف باصنافها الفرعية في جدول الاصناف، والخدمات في قسمها
     * بتكلفتها ومصدرها من بطاقة الخدمة. كل بند مربوط ببند العرض.
     *
     * @return array<string, mixed>
     */
    public function quotationDefaults(SalesQuotation $quotation, ?string $orderDate = null): array
    {
        $quotation->loadMissing(['tax', 'items.product.translations', 'items.pieces']);
        $rate = app(CurrencyExchangeRateService::class)->rateFor($quotation->currency_id, $orderDate ?: now()->toDateString());
        [$services, $goods] = $quotation->items->partition(fn ($item): bool => SalesServiceLine::isService($item->product));
        $labels = ProductSearch::labels($services->pluck('product_id')->all());

        return [
            'sales_quotation_id' => $quotation->id,
            'client_id' => $quotation->client_id,
            'branch_id' => $quotation->branch_id,
            'warehouse_id' => $quotation->warehouse_id,
            'currency_id' => $quotation->currency_id,
            'tax_id' => $quotation->tax_id,
            'exchange_rate' => $rate ? number_format($rate, 8, '.', '') : null,
            'order_discount' => number_format((float) $quotation->quotation_discount, 4, '.', ''),
            'items' => collect(ItemsGridState::rows($goods->values(), quantityIsWeight: true))
                ->map(fn (array $row): array => array_replace($row, [
                    '_key' => 'quotation-item-'.$row['id'],
                    'id' => null,
                    'sales_quotation_item_id' => $row['id'],
                    'pieces' => array_map(fn (array $piece): array => array_replace($piece, [
                        '_key' => 'quotation-piece-'.$piece['id'],
                        'id' => null,
                    ]), $row['pieces']),
                ]))
                ->all(),
            'services' => $services->values()->map(fn ($item): array => [
                '_key' => 'quotation-service-'.$item->id,
                'id' => null,
                'sales_quotation_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_label' => $labels[$item->product_id] ?? (string) $item->description,
                'description' => $item->description,
                'pricing_type' => 'simple',
                'pricing_basis' => SalesServiceLine::defaultPricingBasis($item->product),
                'quantity' => number_format((float) $item->quantity, 4, '.', ''),
                'unit_price' => number_format((float) $item->unit_price, 4, '.', ''),
                'discount_mode' => 'rate',
                'discount_rate' => number_format((float) $item->discount_rate, 4, '.', ''),
                'discount_amount' => number_format((float) $item->discount_amount, 4, '.', ''),
                'line_total' => number_format((float) $item->line_total, 4, '.', ''),
                'cost_source' => SalesServiceLine::defaultCostSource($item->product),
                'service_provider_id' => null,
                'unit_cost' => number_format($item->product_id ? app(StockLedgerService::class)->averageCost((int) $item->product_id) : 0, 4, '.', ''),
                'notes' => $item->notes,
            ])->all(),
        ];
    }

    public function update(SalesOrder $order, array $data): SalesOrder
    {
        if (! $order->canBeEdited()) {
            throw new DomainException(__('Only editable sales orders can be updated.'));
        }

        return DB::transaction(function () use ($order, $data) {
            $items = $this->normalizeItems($data['items'] ?? []);
            $materials = $this->normalizeCustomerMaterials($data);
            $data = $this->normalizeData($data, $order->salesQuotation, false);
            unset($data['reference_no'], $data['created_by'], $data['confirmed_at'], $data['prepared_at'], $data['invoiced_at']);

            $data = array_merge($data, $this->calculateTotals($items, $data['order_discount'], $data['tax_rate']));

            $order->update($data);
            $this->syncItems($order, $items);

            if ($materials !== null) {
                $this->syncCustomerMaterials($order, $materials);
            }

            $this->notifyPurchasingWhenNeeded($order);

            return $order->refresh()->load(self::RELATIONS);
        });
    }

    public function delete(SalesOrder $order): void
    {
        if (! $order->canBeDeleted()) {
            throw new DomainException(__('Only draft sales orders can be deleted.'));
        }

        DB::transaction(function () use ($order) {
            $quotation = $order->salesQuotation;

            $order->items()->delete();
            $order->delete();

            if ($quotation && ! $quotation->salesOrders()->exists()) {
                $quotation->update([
                    'status' => SalesQuotationStatus::APPROVED->value(),
                    'converted_at' => null,
                ]);
            }
        });
    }

    public function confirm(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order) {
            $order = $this->transition($order, SalesOrderStatus::CONFIRMED, ['confirmed_at' => now()]);
            $this->notifyPurchasingWhenNeeded($order);

            return $order->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * طلبية التوريد المباشر تنتقل لقسم المشتريات عند تأكيدها (او عند تحويل طلبية مؤكدة الى توريد
     * مباشر)، مرة واحدة فقط؛ والاشعار يرسل بعد اعتماد المعاملة حتى لا يصل عن طلبية تراجعت.
     */
    private function notifyPurchasingWhenNeeded(SalesOrder $order): void
    {
        if (! $order->isDirectSupply()) {
            if ($order->purchasing_notified_at) {
                $order->update(['purchasing_notified_at' => null]);
            }

            return;
        }

        if ($order->purchasing_notified_at || in_array($order->status, [SalesOrderStatus::DRAFT, SalesOrderStatus::CANCELLED], true)) {
            return;
        }

        $order->update(['purchasing_notified_at' => now()]);

        DB::afterCommit(fn () => app(PurchasingNotifier::class)->directSupplyRequested($order));
    }

    public function sendToPreparation(SalesOrder $order): SalesOrder
    {
        return $this->transition($order, SalesOrderStatus::PREPARING, ['prepared_at' => now()]);
    }

    public function markInvoiced(SalesOrder $order): SalesOrder
    {
        return $this->transition($order, SalesOrderStatus::INVOICED, ['invoiced_at' => now()]);
    }

    public function cancel(SalesOrder $order): SalesOrder
    {
        return $this->transition($order, SalesOrderStatus::CANCELLED);
    }

    private function transition(SalesOrder $order, SalesOrderStatus $status, array $extra = []): SalesOrder
    {
        return DB::transaction(function () use ($order, $status, $extra) {
            $order = SalesOrder::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($order->status === SalesOrderStatus::INVOICED || $order->status === SalesOrderStatus::CANCELLED) {
                throw new DomainException(__('Final sales orders cannot be changed.'));
            }

            if ($status !== SalesOrderStatus::CANCELLED && ($order->items->isEmpty() || (float) $order->total_amount <= 0)) {
                throw new DomainException(__('Sales order must have at least one item.'));
            }

            $order->update(array_merge(['status' => $status->value()], $extra));

            return $order->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * قيم المستند المصدر (الضريبة والخصم والملاحظات) تُورَّث عند الانشاء فقط؛ عند التعديل
     * يُحفظ ما ادخله المستخدم ولو افرغ الحقل.
     */
    private function normalizeData(array $data, ?SalesQuotation $quotation = null, bool $inheritFromSource = true): array
    {
        $orderDate = $data['order_date'] ?? now()->toDateString();
        $currencyId = (int) ($data['currency_id'] ?? $quotation?->currency_id ?? 0);
        $tax = ! empty($data['tax_id'] ?? null) ? Tax::query()->find($data['tax_id']) : ($inheritFromSource ? $quotation?->tax : null);
        $exchangeRate = app(CurrencyExchangeRateService::class)->rateFor($currencyId, $orderDate);

        if (! $exchangeRate) {
            throw new DomainException(__('Exchange rate is missing for selected currency and order date.'));
        }

        $isDirect = ($data['fulfillment_type'] ?? null) === SalesOrderFulfillmentType::DIRECT->value;

        return [
            'sales_quotation_id' => filled($data['sales_quotation_id'] ?? null) ? (int) $data['sales_quotation_id'] : $quotation?->id,
            'client_id' => (int) ($data['client_id'] ?? $quotation?->client_id),
            'branch_id' => filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : $quotation?->branch_id,
            'warehouse_id' => filled($data['warehouse_id'] ?? null) ? (int) $data['warehouse_id'] : $quotation?->warehouse_id,
            'fulfillment_type' => $isDirect ? SalesOrderFulfillmentType::DIRECT->value : SalesOrderFulfillmentType::WAREHOUSE->value,
            'direct_supplier_id' => $isDirect && filled($data['direct_supplier_id'] ?? null) ? (int) $data['direct_supplier_id'] : null,
            'order_date' => $orderDate,
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'currency_id' => $currencyId,
            'tax_id' => $tax?->id,
            'exchange_rate' => $this->decimalValue($exchangeRate, 1, 8),
            'order_discount' => $this->decimalValue($data['order_discount'] ?? ($inheritFromSource ? $quotation?->quotation_discount : null) ?? 0),
            'tax_rate' => $this->decimalValue($tax?->rate ?? 0),
            'notes' => $data['notes'] ?? ($inheritFromSource ? $quotation?->notes : null),
        ];
    }

    private function normalizeItems(array $items): array
    {
        return collect($items)
            ->filter(fn (array $item) => filled($item['product_id'] ?? null))
            ->map(function (array $item): array {
                $product = Product::query()->with('translations')->find($item['product_id']);
                $quantity = $this->decimalValue($item['quantity'] ?? 1, 1);
                $unitPrice = $this->decimalValue($item['unit_price'] ?? $product?->base_price ?? 0);
                $discountMode = ($item['discount_mode'] ?? 'rate') === 'amount' ? 'amount' : 'rate';
                $discountRate = $this->decimalValue($item['discount_rate'] ?? 0);
                $discountAmount = $this->decimalValue($item['discount_amount'] ?? 0);

                if ($quantity <= 0) {
                    throw new DomainException(__('Sales order item quantity must be greater than zero.'));
                }

                if ($unitPrice < 0) {
                    throw new DomainException(__('Sales order item price cannot be negative.'));
                }

                if ($discountRate < 0 || $discountRate > 100) {
                    throw new DomainException(__('Sales order item discount must be between 0 and 100.'));
                }

                // الخدمة تُسعَّر بكميتها دائماً (كغم او نقلات او وحدات)، فلا قطع ولا وزن لها.
                $isService = SalesServiceLine::isService($product);
                $isWeightPriced = ! $isService && $product?->pricing_type === 'weight';
                $pieces = $isWeightPriced ? WeightPieces::normalize($item['pieces'] ?? []) : [];
                $totalWeight = round(collect($pieces)->sum('total_weight'), 4);
                $actualWeight = max($this->decimalValue($item['actual_weight'] ?? 0), 0);
                // المبلغ بالوزن الفعلي فقط، وصفر بدونه؛ الوزن الكلي للعرض والمخزون.
                $gross = $isWeightPriced
                    ? round(WeightPieces::pricingWeight($pieces, $actualWeight) * $unitPrice, 4)
                    : round($quantity * $unitPrice, 4);

                if ($discountMode === 'amount') {
                    if ($discountAmount < 0) {
                        throw new DomainException(__('Sales order item discount amount cannot be negative.'));
                    }

                    if ($discountAmount > $gross) {
                        throw new DomainException(__('Sales order item discount amount cannot exceed item total.'));
                    }

                    $discountRate = $gross > 0 ? round(($discountAmount / $gross) * 100, 4) : 0;
                } else {
                    $discountAmount = round($gross * ($discountRate / 100), 4);
                }

                return array_merge([
                    'id' => $item['id'] ?? null,
                    'sales_quotation_item_id' => filled($item['sales_quotation_item_id'] ?? null) ? (int) $item['sales_quotation_item_id'] : null,
                    'product_id' => (int) $item['product_id'],
                    'barcode' => $item['barcode'] ?? $product?->barcode,
                    'description' => $item['description'] ?? $product?->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_rate' => $discountRate,
                    'discount_amount' => $discountAmount,
                    'line_total' => max(round($gross - $discountAmount, 4), 0),
                    'total_weight' => $isService ? 0 : $totalWeight,
                    'actual_weight' => $isService ? null : ($actualWeight ?: null),
                    'notes' => $item['notes'] ?? null,
                    'created_by' => $item['created_by'] ?? auth()->id(),
                    'pieces' => $pieces,
                ], SalesServiceLine::fields($item, $product, $quantity));
            })
            ->values()
            ->toArray();
    }

    private function quotationItemsForOrder(SalesQuotation $quotation): array
    {
        $quotation->loadMissing(['items.product', 'items.pieces']);

        return $quotation->items
            ->map(fn ($item): array => [
                'sales_quotation_item_id' => $item->id,
                'product_id' => $item->product_id,
                'barcode' => $item->barcode,
                'description' => $item->description,
                'quantity' => $item->quantity,
                // صنف الوزن ينتقل باصنافه الفرعية؛ سطر قديم بلا اصناف فرعية يُسعَّر بوزنه
                // المسعَّر في العرض وزناً فعلياً، والا وصل الطلبية باجمالي صفر.
                'actual_weight' => $item->actual_weight
                    ?? ($item->product?->pricing_type === 'weight' && $item->pieces->isEmpty() ? $item->quantity : null),
                'unit_price' => $item->unit_price,
                'discount_rate' => $item->discount_rate,
                'discount_amount' => $item->discount_amount,
                'notes' => $item->notes,
                'pieces' => $item->pieces->map(fn ($piece): array => [
                    'product_id' => $piece->product_id,
                    'barcode' => $piece->barcode,
                    'description' => $piece->description,
                    'piece_count' => $piece->piece_count,
                    'piece_length' => $piece->piece_length,
                    'weight_per_meter' => $piece->weight_per_meter,
                ])->values()->all(),
            ])
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

    private function syncItems(SalesOrder $order, array $items): void
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

            WeightPieces::sync($existingItem->pieces(), $pieces);
        }
    }

    /**
     * مواد العميل تُستبدل بما ارسلته الواجهة. بلا المفتاح (كتحويل عرض سعر) تبقى كما هي.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function normalizeCustomerMaterials(array $data): ?array
    {
        if (! array_key_exists('customer_materials', $data)) {
            return null;
        }

        return collect($data['customer_materials'] ?? [])
            ->filter(fn (array $material): bool => filled($material['product_id'] ?? null) || filled($material['description'] ?? null))
            ->values()
            ->map(function (array $material, int $index): array {
                $product = filled($material['product_id'] ?? null) ? Product::query()->with('translations')->find($material['product_id']) : null;
                $quantity = $this->decimalValue($material['quantity'] ?? 0);
                $weight = $this->decimalValue($material['weight'] ?? 0);

                if ($quantity < 0 || $weight < 0) {
                    throw new DomainException(__('Customer material quantity and weight cannot be negative.'));
                }

                if ($quantity <= 0 && $weight <= 0) {
                    throw new DomainException(__('Enter the quantity or the weight of the customer material ":item".', [
                        'item' => $material['description'] ?? $product?->name,
                    ]));
                }

                return [
                    'product_id' => $product?->id,
                    'description' => filled($material['description'] ?? null) ? $material['description'] : $product?->name,
                    'quantity' => $quantity,
                    'weight' => $weight,
                    'received_date' => filled($material['received_date'] ?? null) ? $material['received_date'] : null,
                    'notes' => $material['notes'] ?? null,
                    'sort_order' => $index,
                    'created_by' => auth()->id(),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $materials
     */
    private function syncCustomerMaterials(SalesOrder $order, array $materials): void
    {
        $order->customerMaterials()->delete();

        foreach ($materials as $material) {
            $order->customerMaterials()->create($material);
        }
    }

    private function quotation(mixed $quotationId, bool $lockForUpdate = false): ?SalesQuotation
    {
        if (blank($quotationId)) {
            return null;
        }

        $query = SalesQuotation::query()->with(['tax', 'items', 'salesOrders'])->whereKey($quotationId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
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
