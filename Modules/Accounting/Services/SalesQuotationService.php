<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\SalesQuotation;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Enums\SalesQuotationStatus;
use Modules\Accounting\Support\DocumentLineTotals;
use Modules\Accounting\Support\GridLine;
use Modules\Accounting\Support\ItemsGrid;
use Modules\Accounting\Support\WeightPieces;
use Modules\Core\Services\ReferenceNumberService;
use Modules\Items\Entities\Product;

class SalesQuotationService
{
    /**
     * عرض جديد برأسه وسطوره (السطور اختيارية: صفحة Livewire القديمة كانت تضيفها بعد الانشاء).
     */
    public function create(array $data): SalesQuotation
    {
        ItemsGrid::ensureWeightLines($data['items'] ?? []);

        return DB::transaction(function () use ($data) {
            $items = $this->normalizeItems($data['items'] ?? []);
            $data = $this->normalizeData($data);
            $data = array_merge($data, $this->calculateTotals($items, $data['quotation_discount'], $data['tax_rate']));
            $data['reference_no'] = app(ReferenceNumberService::class)->generate(
                'sales_quotation',
                $data['quotation_date'],
                ['prefix' => 'SQUO']
            );
            $data['status'] = SalesQuotationStatus::DRAFT->value();
            $data['created_by'] = $data['created_by'] ?? auth()->id();

            $quotation = SalesQuotation::create($data);
            $this->syncItems($quotation, $items);

            return $quotation->refresh()->load(['client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces']);
        });
    }

    /**
     * سطر الوزن الجديد سطر مجموعة سعر بقطع من ألوانها لها وزن (ItemsGrid::ensureWeightLines).
     * القاعدة لشاشة الادخال: تحويل العرض الى طلبية لا يمر بها.
     */
    public function update(SalesQuotation $quotation, array $data): SalesQuotation
    {
        if (! $quotation->canBeEdited()) {
            throw new DomainException(__('Only editable sales quotations can be updated.'));
        }

        ItemsGrid::ensureWeightLines($data['items'] ?? []);

        return DB::transaction(function () use ($quotation, $data) {
            $items = $this->normalizeItems($data['items'] ?? []);
            $data = $this->normalizeData($data);
            unset($data['reference_no'], $data['created_by'], $data['converted_at']);

            $data = array_merge($data, $this->calculateTotals($items, $data['quotation_discount'], $data['tax_rate']));

            $quotation->update($data);
            $this->syncItems($quotation, $items);

            return $quotation->refresh()->load(['client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces']);
        });
    }

    /**
     * يحفظ التعديلات (ما دام العرض قابلاً للتعديل) ثم يعتمده، كما كان زر «اعتماد».
     */
    public function saveAndApprove(SalesQuotation $quotation, ?array $data = null): SalesQuotation
    {
        return DB::transaction(function () use ($quotation, $data) {
            if ($data !== null && $quotation->canBeEdited()) {
                $quotation = $this->update($quotation, $data);
            }

            return $this->approve($quotation);
        });
    }

    /**
     * طلبية بيع من العرض، بعد حفظ تعديلاته ان كان قابلاً للتعديل وأُرسلت.
     */
    public function convertToSalesOrder(SalesQuotation $quotation, ?array $data = null): SalesOrder
    {
        return DB::transaction(function () use ($quotation, $data) {
            if ($data !== null && $quotation->canBeEdited()) {
                $quotation = $this->update($quotation, $data);
            }

            return app(SalesOrderService::class)->createFromQuotation($quotation);
        });
    }

    public function delete(SalesQuotation $quotation): void
    {
        if (! $quotation->canBeDeleted()) {
            throw new DomainException(__('Only draft sales quotations without sales orders can be deleted.'));
        }

        DB::transaction(function () use ($quotation) {
            $quotation->items()->delete();
            $quotation->delete();
        });
    }

    public function approve(SalesQuotation $quotation): SalesQuotation
    {
        return DB::transaction(function () use ($quotation) {
            $quotation = SalesQuotation::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($quotation->id);

            if (! in_array($quotation->status, [SalesQuotationStatus::DRAFT, SalesQuotationStatus::APPROVED], true)) {
                throw new DomainException(__('Only draft sales quotations can be approved.'));
            }

            if ($quotation->items->isEmpty() || (float) $quotation->total_amount <= 0) {
                throw new DomainException(__('Sales quotation must have at least one item.'));
            }

            $quotation->update(['status' => SalesQuotationStatus::APPROVED->value()]);

            return $quotation->refresh()->load(['client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces']);
        });
    }

    public function cancel(SalesQuotation $quotation): SalesQuotation
    {
        if ($quotation->salesOrders()->exists()) {
            throw new DomainException(__('Sales quotation with sales orders cannot be cancelled.'));
        }

        $quotation->update(['status' => SalesQuotationStatus::CANCELLED->value()]);

        return $quotation->refresh()->load(['client', 'branch', 'warehouse', 'currency', 'tax', 'items.product', 'items.pieces']);
    }

    private function normalizeData(array $data): array
    {
        $quotationDate = $data['quotation_date'] ?? now()->toDateString();
        $currencyId = (int) ($data['currency_id'] ?? 0);
        $tax = ! empty($data['tax_id']) ? Tax::query()->find($data['tax_id']) : null;
        $exchangeRate = app(CurrencyExchangeRateService::class)->rateFor($currencyId, $quotationDate);

        if (! $exchangeRate) {
            throw new DomainException(__('Exchange rate is missing for selected currency and quotation date.'));
        }

        return [
            'client_id' => (int) $data['client_id'],
            'branch_id' => filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null,
            'warehouse_id' => filled($data['warehouse_id'] ?? null) ? (int) $data['warehouse_id'] : null,
            'quotation_date' => $quotationDate,
            'valid_until' => $data['valid_until'] ?? null,
            'currency_id' => $currencyId,
            'tax_id' => $tax?->id,
            'exchange_rate' => $this->decimalValue($exchangeRate, 1, 8),
            'quotation_discount' => $this->decimalValue($data['quotation_discount'] ?? 0),
            'tax_rate' => $this->decimalValue($tax?->rate ?? 0),
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function normalizeItems(array $items): array
    {
        return collect($items)
            ->filter(fn (array $item) => GridLine::hasOwner($item))
            ->map(function (array $item): array {
                // سطر صنف، او سطر مجموعة سعر بلا صنف (التصنيف × اللون) قطعه ألوان مقاطعه.
                $product = GridLine::product($item);
                $quantity = $this->decimalValue($item['quantity'] ?? 1, 1);
                // صنف الوزن باصنافه الفرعية: الكمية المسعَّرة (كغم) = الوزن الفعلي، وصفر بدونه.
                $weight = WeightPieces::line($item, $product);
                $quantity = $weight['pricing_weight'] ?? $quantity;
                $unitPrice = $this->decimalValue($item['unit_price'] ?? $product?->base_price ?? 0);
                $discountMode = ($item['discount_mode'] ?? 'rate') === 'amount' ? 'amount' : 'rate';
                $discountRate = $this->decimalValue($item['discount_rate'] ?? 0);
                $discountAmount = $this->decimalValue($item['discount_amount'] ?? 0);

                // سطر وزن له قطع بوزن وبلا وزن فعلي جائز بمبلغ صفر.
                if ($quantity <= 0 && $weight['total_weight'] <= 0) {
                    throw new DomainException(__('Sales quotation item quantity must be greater than zero.'));
                }

                if ($unitPrice < 0) {
                    throw new DomainException(__('Sales quotation item price cannot be negative.'));
                }

                if ($discountRate < 0 || $discountRate > 100) {
                    throw new DomainException(__('Sales quotation item discount must be between 0 and 100.'));
                }

                $gross = round($quantity * $unitPrice, 4);

                if ($discountMode === 'amount') {
                    if ($discountAmount < 0) {
                        throw new DomainException(__('Sales quotation item discount amount cannot be negative.'));
                    }

                    if ($discountAmount > $gross) {
                        throw new DomainException(__('Sales quotation item discount amount cannot exceed item total.'));
                    }

                    $discountRate = $gross > 0 ? round(($discountAmount / $gross) * 100, 4) : 0;
                } else {
                    $discountAmount = round($gross * ($discountRate / 100), 4);
                }

                return [
                    'id' => $item['id'] ?? null,
                    'product_id' => $product?->id,
                    'category_finish_id' => GridLine::groupId($item),
                    'barcode' => $item['barcode'] ?? $product?->barcode,
                    'description' => GridLine::description($item, $product),
                    'quantity' => $quantity,
                    'total_weight' => $weight['total_weight'],
                    'actual_weight' => $weight['actual_weight'],
                    'unit_price' => $unitPrice,
                    'discount_rate' => $discountRate,
                    'discount_amount' => $discountAmount,
                    'line_total' => max(round($gross - $discountAmount, 4), 0),
                    'notes' => $item['notes'] ?? null,
                    'created_by' => $item['created_by'] ?? auth()->id(),
                    'pieces' => $weight['pieces'],
                ];
            })
            ->values()
            ->toArray();
    }

    private function calculateTotals(array $items, float $quotationDiscount, float $taxRate): array
    {
        $totals = DocumentLineTotals::document(
            array_map(fn (array $item): array => [
                'gross' => round($item['quantity'] * $item['unit_price'], 4),
                'discount_amount' => $item['discount_amount'],
            ], $items),
            $quotationDiscount,
            $taxRate,
        );

        return [
            'sub_total' => $totals['sub_total'],
            'items_discount' => $totals['items_discount'],
            'quotation_discount' => $totals['document_discount'],
            'tax_amount' => $totals['tax_amount'],
            'total_amount' => $totals['total_amount'],
        ];
    }

    private function syncItems(SalesQuotation $quotation, array $items): void
    {
        $incomingIds = collect($items)->pluck('id')->filter()->values();

        $quotation->items()
            ->when($incomingIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $incomingIds))
            ->when($incomingIds->isEmpty(), fn ($query) => $query)
            ->delete();

        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $pieces = $item['pieces'] ?? [];
            unset($item['id'], $item['pieces']);

            if ($id && $existingItem = $quotation->items()->whereKey($id)->first()) {
                $existingItem->update($item);
            } else {
                $existingItem = $quotation->items()->create($item);
            }

            WeightPieces::sync($existingItem->pieces(), $pieces);
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
