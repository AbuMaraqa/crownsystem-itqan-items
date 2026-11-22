<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\PurchaseQuotationStatus;
use Modules\Accounting\Support\ItemsGridState;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Items\Support\ProductSearch;

/**
 * عرض سعر الشراء: يُنشأ من طلبية الشراء وسطوره هي سطورها دائماً (لا تُضاف اصناف ولا
 * تُحذف من العرض)؛ المورد يسعّرها فقط. ما دام العرض مسودة تتبع سطوره الطلبية (سطر جديد
 * يظهر بلا سعر، والمحذوف يختفي، والكميات تتحدث)، وبعد اعتماده تثبت اسعاره.
 */
class PurchaseQuotationService
{
    private const RELATIONS = ['purchaseOrder', 'client', 'branch', 'currency', 'tax', 'items.product', 'approvedBy', 'cancelledBy', 'createdBy'];

    public function createFromOrder(PurchaseOrder $order, array $data = []): PurchaseQuotation
    {
        return DB::transaction(function () use ($order, $data) {
            // قفل الطلبية يمنع طلبين متزامنين من انشاء عرضين لها.
            $order = PurchaseOrder::query()
                ->with(['items.product'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $this->assertOrderCanBeQuoted($order);

            if ($order->purchaseQuotations()->active()->exists()) {
                throw new DomainException(__('This purchase order already has a purchase quotation.'));
            }

            $header = $this->normalizeHeader(array_replace([
                'quotation_date' => now()->toDateString(),
                'currency_id' => app(CurrencyExchangeRateService::class)->baseCurrencyId(),
            ], $data));

            $quotation = PurchaseQuotation::create(array_merge($header, [
                'purchase_order_id' => $order->id,
                'client_id' => $order->client_id,
                'branch_id' => $order->branch_id,
                'reference_no' => app(ReferenceNumberService::class)->generate('purchase_quotation', $header['quotation_date'], ['prefix' => 'PQUO']),
                'status' => PurchaseQuotationStatus::DRAFT->value,
                'created_by' => auth()->id(),
            ]));

            $this->syncLines($quotation, $order, $data['items'] ?? []);

            return $quotation->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * @param  array{items?: array<int, array<string, mixed>>}  $data  items مفتاحها purchase_order_item_id
     */
    public function update(PurchaseQuotation $quotation, array $data): PurchaseQuotation
    {
        return DB::transaction(function () use ($quotation, $data) {
            $quotation = $this->lock($quotation);

            if (! $quotation->canBeEdited()) {
                throw new DomainException(__('Only draft purchase quotations can be updated.'));
            }

            $quotation->update($this->normalizeHeader($data));
            $this->syncLines($quotation, $quotation->purchaseOrder()->with('items.product')->firstOrFail(), $data['items'] ?? null);

            return $quotation->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * الاعتماد يثبت الاسعار: تُحدَّث السطور من الطلبية اولاً، ثم يجب ان يكون لكل سطر سعر.
     */
    public function approve(PurchaseQuotation $quotation): PurchaseQuotation
    {
        return DB::transaction(function () use ($quotation) {
            $quotation = $this->lock($quotation);

            if (! $quotation->canBeApproved()) {
                throw new DomainException(__('Only draft purchase quotations can be approved.'));
            }

            $order = $quotation->purchaseOrder()->with('items.product')->firstOrFail();
            $this->assertOrderCanBeQuoted($order);
            $this->syncLines($quotation, $order, null);
            $quotation->load('items');

            if ($quotation->items->isEmpty()) {
                throw new DomainException(__('Purchase quotation must have at least one item.'));
            }

            $unpriced = $quotation->items
                ->filter(fn ($item): bool => (float) $item->unit_price <= 0)
                ->map(fn ($item): string => (string) ($item->description ?: ProductSearch::label($item->product_id)))
                ->values();

            if ($unpriced->isNotEmpty()) {
                throw new DomainException(__('Enter the supplier price of every item before approving. Missing: :items', [
                    'items' => $unpriced->implode('، '),
                ]));
            }

            $quotation->update([
                'status' => PurchaseQuotationStatus::APPROVED->value,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            return $quotation->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * زر «اعتماد» في صفحة العرض: يحفظ المسودة بما أُدخل اولاً ثم يعتمد. الحفظ يبقى ولو رُفض
     * الاعتماد (سعر ناقص مثلاً)، كما كانت الصفحة.
     */
    public function saveAndApprove(PurchaseQuotation $quotation, ?array $data = null): PurchaseQuotation
    {
        if ($data !== null && $quotation->canBeEdited()) {
            $quotation = $this->update($quotation, $data);
        }

        return $this->approve($quotation);
    }

    /**
     * الالغاء يحرر الطلبية لعرض جديد. العرض المفوتر لا يُلغى قبل الغاء فاتورته.
     */
    public function cancel(PurchaseQuotation $quotation): PurchaseQuotation
    {
        return DB::transaction(function () use ($quotation) {
            $quotation = $this->lock($quotation);

            if (! $quotation->canBeCancelled() || $quotation->activePurchaseInvoices()->exists()) {
                throw new DomainException(__('An invoiced purchase quotation cannot be cancelled. Cancel its purchase invoice first.'));
            }

            $quotation->update([
                'status' => PurchaseQuotationStatus::CANCELLED->value,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
            ]);

            return $quotation->refresh()->load(self::RELATIONS);
        });
    }

    public function delete(PurchaseQuotation $quotation): void
    {
        DB::transaction(function () use ($quotation) {
            $quotation = $this->lock($quotation);

            if (! $quotation->canBeDeleted() || $quotation->purchaseInvoices()->exists()) {
                throw new DomainException(__('Only draft purchase quotations can be deleted.'));
            }

            $quotation->items()->delete();
            $quotation->delete();
        });
    }

    /**
     * عند انشاء فاتورة من العرض.
     */
    public function markInvoiced(PurchaseQuotation $quotation): void
    {
        if (! $quotation->isApproved()) {
            throw new DomainException(__('Only approved purchase quotations can be invoiced.'));
        }

        $quotation->update(['status' => PurchaseQuotationStatus::INVOICED->value]);
    }

    /**
     * حين لا يبقى للعرض فاتورة غير ملغاة (حُذفت او أُلغيت) يعود معتمداً فيُفوتر من جديد.
     */
    public function restoreAfterInvoiceRemoved(PurchaseQuotation $quotation): void
    {
        if ($quotation->status !== PurchaseQuotationStatus::INVOICED || $quotation->activePurchaseInvoices()->exists()) {
            return;
        }

        $quotation->update(['status' => PurchaseQuotationStatus::APPROVED->value]);
    }

    /**
     * سطور فاتورة الشراء من العرض: الكمية كمية الطلبية الحالية (الوزن الفعلي ان سُجل بعد
     * الاستلام) والسعر ونسبة الخصم من العرض. سطر أُضيف الى الطلبية بعد الاعتماد يأتي
     * بلا سعر ليُسعّر في الفاتورة.
     *
     * @return array<int, array<string, mixed>>
     */
    public function invoiceLines(PurchaseQuotation $quotation): array
    {
        $order = $quotation->purchaseOrder()->with(['items.product', 'items.pieces'])->firstOrFail();
        $quoted = $quotation->items()->get()->keyBy('purchase_order_item_id');
        $orderItems = $order->items->keyBy('id');

        return $this->orderLines($order)
            ->map(function (array $line) use ($quoted, $orderItems): array {
                $item = $quoted->get($line['purchase_order_item_id']);
                $orderItem = $orderItems->get($line['purchase_order_item_id']);

                return array_merge($line, [
                    'category_finish_id' => $orderItem?->category_finish_id,
                    'actual_weight' => $orderItem?->actual_weight,
                    'pieces' => $orderItem ? PurchaseInvoiceService::orderPieces($orderItem) : [],
                    'unit_price' => $item ? (float) $item->unit_price : 0,
                    'discount_mode' => 'rate',
                    'discount_rate' => $item ? (float) $item->discount_rate : 0,
                    'discount_amount' => 0,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * سطور الطلبية في جدول الاصناف الموحد: الصنف الرئيسي واصنافه الفرعية كما في الطلبية،
     * بكمية الفوترة (الوزن للصنف الموزون) وسعر فارغ يُدخله المورد. مفتاح السطر purchase_order_item_id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function orderGridLines(PurchaseOrder $order): array
    {
        $order->loadMissing(['items.product', 'items.pieces']);
        $items = $order->items->filter(fn ($item) => filled($item->product_id) || $item->isPriceGroupLine())->keyBy('id');

        return collect(ItemsGridState::rows($items))
            ->map(function (array $row) use ($items) {
                $item = $items->get($row['id']);
                $isWeight = $row['pricing_type'] === 'weight';
                $quantity = round($item->invoiceQuantity(), 4);

                return array_merge($row, [
                    '_key' => 'po-item-'.$item->id,
                    'id' => null,
                    'purchase_order_item_id' => $item->id,
                    'description' => $item->description ?: $row['product_label'],
                    'is_weight' => $isWeight,
                    'quantity' => $quantity,
                    // الوزن المسعَّر = كمية الفوترة: الفعلي او مجموع الاصناف الفرعية، وسطر قديم بلا
                    // اصناف فرعية بوزنه، فيطابق الجدول ما تحفظه الخدمة (الكمية × السعر).
                    'actual_weight' => $isWeight && $item->pieces->isEmpty() ? $quantity : $item->actual_weight,
                    'unit_price' => 0,
                    'discount_mode' => 'rate',
                    'discount_rate' => 0,
                    'discount_amount' => 0,
                    'line_total' => 0,
                    'notes' => null,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * سطور العرض في جدول الاصناف: المسودة تعرض سطور الطلبية الحالية باسعارها المحفوظة،
     * والمعتمد يعرض ما اعتُمد. ان لم تعد الطلبية موجودة تُعرض سطور العرض كما هي بلا اصناف فرعية.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gridLines(PurchaseQuotation $quotation): array
    {
        $quotation->loadMissing(['items.product', 'purchaseOrder.items.product', 'purchaseOrder.items.pieces']);

        if ($quotation->purchaseOrder) {
            $saved = $quotation->items->keyBy('purchase_order_item_id');

            return collect($this->orderGridLines($quotation->purchaseOrder))
                ->when(! $quotation->canBeEdited(), fn ($lines) => $lines->filter(fn (array $line) => $saved->has($line['purchase_order_item_id'])))
                ->map(function (array $line) use ($saved) {
                    $item = $saved->get($line['purchase_order_item_id']);

                    return array_merge($line, $item ? [
                        'unit_price' => (float) $item->unit_price,
                        'discount_rate' => (float) $item->discount_rate,
                        'notes' => $item->notes,
                    ] : []);
                })
                ->values()
                ->all();
        }

        $items = $quotation->items->each->setRelation('pieces', collect());

        return collect(ItemsGridState::rows($items))
            ->map(fn (array $row) => array_merge($row, [
                'purchase_order_item_id' => $row['id'] ? $items->firstWhere('id', $row['id'])?->purchase_order_item_id : null,
                'id' => null,
                'description' => $row['description'] ?: $row['product_label'],
                'actual_weight' => $row['pricing_type'] === 'weight' ? $row['quantity'] : null,
            ]))
            ->all();
    }

    /**
     * اجماليات سطر ومستند بالمعادلة نفسها التي تُحفظ بها، لتعرضها الصفحة اثناء الكتابة.
     *
     * @param  array<int, array{quantity: mixed, unit_price: mixed, discount_rate: mixed}>  $lines
     * @return array{lines: array<int, array{gross: float, discount_amount: float, line_total: float}>, sub_total: float, items_discount: float, quotation_discount: float, tax_amount: float, total_amount: float}
     */
    public function totals(array $lines, mixed $quotationDiscount, mixed $taxRate): array
    {
        $computed = [];

        foreach ($lines as $key => $line) {
            $computed[$key] = $this->lineTotals(
                $this->decimal($line['quantity'] ?? 0),
                $this->decimal($line['unit_price'] ?? 0),
                $this->decimal($line['discount_rate'] ?? 0)
            );
        }

        $subTotal = round(collect($computed)->sum('gross'), 4);
        $itemsDiscount = round(collect($computed)->sum('discount_amount'), 4);
        $net = max(round($subTotal - $itemsDiscount, 4), 0);
        $discount = min(max($this->decimal($quotationDiscount), 0), $net);
        $taxable = max(round($net - $discount, 4), 0);
        $taxAmount = round($taxable * ($this->decimal($taxRate) / 100), 4);

        return [
            'lines' => $computed,
            'sub_total' => $subTotal,
            'items_discount' => $itemsDiscount,
            'quotation_discount' => round($discount, 4),
            'tax_amount' => $taxAmount,
            'total_amount' => round($taxable + $taxAmount, 4),
        ];
    }

    private function assertOrderCanBeQuoted(PurchaseOrder $order): void
    {
        if (in_array($order->status, [PurchaseOrderStatus::CANCELLED, PurchaseOrderStatus::INVOICED], true)) {
            throw new DomainException(__('A purchase quotation can only be made for an open purchase order.'));
        }

        if ($order->items->isEmpty()) {
            throw new DomainException(__('Purchase order must have at least one item.'));
        }
    }

    private function lock(PurchaseQuotation $quotation): PurchaseQuotation
    {
        return PurchaseQuotation::query()->lockForUpdate()->findOrFail($quotation->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeHeader(array $data): array
    {
        $date = $data['quotation_date'] ?? now()->toDateString();
        $currencyId = (int) ($data['currency_id'] ?? 0);
        $tax = filled($data['tax_id'] ?? null) ? Tax::query()->find($data['tax_id']) : null;
        $rate = app(CurrencyExchangeRateService::class)->rateFor($currencyId, $date);

        if (! $currencyId || ! $rate) {
            throw new DomainException(__('Exchange rate is missing for selected currency and quotation date.'));
        }

        $validUntil = filled($data['valid_until'] ?? null) ? $data['valid_until'] : null;

        if ($validUntil && $validUntil < $date) {
            throw new DomainException(__('The validity date cannot be before the quotation date.'));
        }

        return [
            'quotation_date' => $date,
            'valid_until' => $validUntil,
            'supplier_reference' => filled($data['supplier_reference'] ?? null) ? trim((string) $data['supplier_reference']) : null,
            'currency_id' => $currencyId,
            'exchange_rate' => round((float) $rate, 8),
            'tax_id' => $tax?->id,
            'tax_rate' => $this->decimal($tax?->rate ?? 0),
            'quotation_discount' => $this->decimal($data['quotation_discount'] ?? 0),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * يعيد بناء سطور العرض من سطور الطلبية الحالية. $prices (مفتاحها purchase_order_item_id)
     * هي ما ادخله المستخدم؛ null تعني الابقاء على الاسعار المحفوظة.
     *
     * @param  array<int|string, array<string, mixed>>|null  $prices
     */
    private function syncLines(PurchaseQuotation $quotation, PurchaseOrder $order, ?array $prices): void
    {
        $saved = $quotation->items()->get()->keyBy('purchase_order_item_id');
        $prices = $prices === null ? null : collect($prices)->keyBy(fn ($line, $key) => (int) ($line['purchase_order_item_id'] ?? $key));
        $lines = $this->orderLines($order);
        $keep = [];

        foreach ($lines as $line) {
            $orderItemId = $line['purchase_order_item_id'];
            $existing = $saved->get($orderItemId);
            $input = $prices?->get($orderItemId);

            $unitPrice = $this->decimal($input['unit_price'] ?? $existing?->unit_price ?? 0);
            $discountRate = $this->decimal($input['discount_rate'] ?? $existing?->discount_rate ?? 0);

            if ($unitPrice < 0) {
                throw new DomainException(__('Purchase quotation item price cannot be negative.'));
            }

            if ($discountRate < 0 || $discountRate > 100) {
                throw new DomainException(__('Purchase quotation item discount must be between 0 and 100.'));
            }

            $totals = $this->lineTotals($line['quantity'], $unitPrice, $discountRate);

            $attributes = [
                'product_id' => $line['product_id'],
                'barcode' => $line['barcode'],
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $unitPrice,
                'discount_rate' => $discountRate,
                'discount_amount' => $totals['discount_amount'],
                'line_total' => $totals['line_total'],
                'notes' => array_key_exists('notes', $input ?? []) ? ($input['notes'] ?: null) : $existing?->notes,
            ];

            if ($existing) {
                $existing->update($attributes);
                $keep[] = $existing->id;
            } else {
                $keep[] = $quotation->items()->create(array_merge($attributes, [
                    'purchase_order_item_id' => $orderItemId,
                    'created_by' => auth()->id(),
                ]))->id;
            }
        }

        $quotation->items()->whereNotIn('id', $keep)->delete();
        $this->refreshTotals($quotation);
    }

    private function refreshTotals(PurchaseQuotation $quotation): void
    {
        $items = $quotation->items()->get();
        $totals = $this->totals(
            $items->map(fn ($item) => ['quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'discount_rate' => $item->discount_rate])->all(),
            $quotation->quotation_discount,
            $quotation->tax_rate
        );

        $quotation->update(collect($totals)->except('lines')->all());
    }

    /**
     * سطور الطلبية بكمية الفوترة: الوزن (الفعلي ان سُجل) للصنف الموزون، والكمية لغيره.
     *
     * @return Collection<int, array{purchase_order_item_id: int, product_id: ?int, barcode: ?string, description: ?string, quantity: float}>
     */
    private function orderLines(PurchaseOrder $order): Collection
    {
        return $order->items
            // سطر صنف، او سطر مجموعة سعر بلا صنف (يُسعَّر بالكيلو كسطر الصنف الرئيسي).
            ->filter(fn (PurchaseOrderItem $item): bool => filled($item->product_id) || $item->isPriceGroupLine())
            ->map(fn (PurchaseOrderItem $item): array => [
                'purchase_order_item_id' => (int) $item->id,
                'product_id' => $item->product_id ? (int) $item->product_id : null,
                'barcode' => $item->barcode,
                'description' => $item->description ?: ($item->product_id ? ProductSearch::label($item->product_id) : null),
                'quantity' => round($item->invoiceQuantity(), 4),
            ])
            ->values();
    }

    /**
     * @return array{gross: float, discount_amount: float, line_total: float}
     */
    private function lineTotals(float $quantity, float $unitPrice, float $discountRate): array
    {
        $gross = round($quantity * $unitPrice, 4);
        $discountRate = min(max($discountRate, 0), 100);
        $discountAmount = round($gross * ($discountRate / 100), 4);

        return [
            'gross' => $gross,
            'discount_amount' => $discountAmount,
            'line_total' => max(round($gross - $discountAmount, 4), 0),
        ];
    }

    private function decimal(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        if (! is_numeric($value)) {
            throw new DomainException(__('Invalid numeric value.'));
        }

        return round((float) $value, 4);
    }
}
