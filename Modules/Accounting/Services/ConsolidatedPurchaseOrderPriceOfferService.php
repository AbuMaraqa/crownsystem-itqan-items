<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderAward;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderPriceOffer;

class ConsolidatedPurchaseOrderPriceOfferService
{
    public function create(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrderPriceOffer
    {
        return DB::transaction(function () use ($order, $data) {
            $items = $this->normalizeItems($data['items'] ?? []);
            $totals = $this->calculateTotals($items);

            $offer = $order->priceOffers()->create([
                'supplier_id' => $data['supplier_id'],
                'currency_id' => $data['currency_id'],
                'offer_date' => $data['offer_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
                ...$totals,
            ]);

            foreach ($items as $item) {
                $offer->items()->create($item);
            }

            return $offer->refresh()->load(['supplier', 'currency', 'items.consolidatedPurchaseOrderItem']);
        });
    }

    public function update(ConsolidatedPurchaseOrderPriceOffer $offer, array $data): ConsolidatedPurchaseOrderPriceOffer
    {
        return DB::transaction(function () use ($offer, $data) {
            $items = $this->normalizeItems($data['items'] ?? []);
            $totals = $this->calculateTotals($items);

            $offer->update([
                'supplier_id' => $data['supplier_id'],
                'currency_id' => $data['currency_id'],
                'offer_date' => $data['offer_date'],
                'notes' => $data['notes'] ?? null,
                ...$totals,
            ]);

            $offer->items()->delete();
            foreach ($items as $item) {
                $offer->items()->create($item);
            }

            return $offer->refresh()->load(['supplier', 'currency', 'items.consolidatedPurchaseOrderItem']);
        });
    }

    public function delete(ConsolidatedPurchaseOrderPriceOffer $offer): void
    {
        DB::transaction(function () use ($offer) {
            if ($offer->award()->exists()) {
                throw new DomainException(__('This price offer is awarded and cannot be deleted. Cancel the award first.'));
            }

            $offer->items()->delete();
            $offer->delete();
        });
    }

    public function award(ConsolidatedPurchaseOrder $order, int $priceOfferId, ?string $notes = null): ConsolidatedPurchaseOrderAward
    {
        return DB::transaction(function () use ($order, $priceOfferId, $notes) {
            $offer = $order->priceOffers()->whereKey($priceOfferId)->first();

            if (! $offer) {
                throw new DomainException(__('The selected price offer no longer exists.'));
            }

            $order->award()->delete();

            return $order->award()->create([
                'price_offer_id' => $offer->id,
                'notes' => $notes,
                'awarded_by' => auth()->id(),
            ]);
        });
    }

    public function cancelAward(ConsolidatedPurchaseOrder $order): void
    {
        $order->award()->delete();
    }

    private function normalizeItems(array $items): array
    {
        return collect($items)
            ->map(function (array $item): array {
                $quantity = $this->decimalValue($item['quantity'] ?? 1, 1);
                $unitPrice = $this->decimalValue($item['unit_price'] ?? 0);
                $discountRate = $this->decimalValue($item['discount_rate'] ?? 0);

                if ($unitPrice < 0) {
                    throw new DomainException(__('Price offer item price cannot be negative.'));
                }

                if ($discountRate < 0 || $discountRate > 100) {
                    throw new DomainException(__('Price offer item discount must be between 0 and 100.'));
                }

                $gross = round($quantity * $unitPrice, 4);
                $discountAmount = round($gross * ($discountRate / 100), 4);

                return [
                    'consolidated_purchase_order_item_id' => $item['consolidated_purchase_order_item_id'] ?? null,
                    'description' => $item['description'] ?? null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_rate' => $discountRate,
                    'discount_amount' => $discountAmount,
                    'bonus_note' => $item['bonus_note'] ?? null,
                    'line_total' => max(round($gross - $discountAmount, 4), 0),
                ];
            })
            ->values()
            ->toArray();
    }

    private function calculateTotals(array $items): array
    {
        $subTotal = collect($items)->sum(fn (array $item) => round($item['line_total'] + $item['discount_amount'], 4));
        $discountAmount = collect($items)->sum('discount_amount');

        return [
            'sub_total' => round($subTotal, 4),
            'discount_amount' => round($discountAmount, 4),
            'total_amount' => round($subTotal - $discountAmount, 4),
        ];
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
