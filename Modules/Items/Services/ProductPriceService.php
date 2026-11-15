<?php

namespace Modules\Items\Services;

use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductPrice;
use Modules\Items\Entities\ProductUnit;
use Modules\Items\Support\ExactDecimal;

/**
 * كتابة سعر الصنف في قائمة اسعار. صف واحد لكل قائمة × صنف × وحدة، و"بلا وحدة" يعني
 * الوحدة الاساسية؛ يحرس ذلك هنا الاستعلام وفي القاعدة الفهرس الفريد على unit_key.
 */
class ProductPriceService
{
    /**
     * جدول اسعار الصنف كله معاً او لا شيء: خطأ في صف لا يترك صفوفاً محفوظة وصفوفاً لا.
     *
     * @param  array<int|string, array<int|string, array<string, mixed>>>  $prices  [قائمة][وحدة الصنف، و0 للوحدة الاساسية]
     */
    public function saveAll(Product $product, array $prices): void
    {
        $lists = PriceList::query()->whereIn('id', array_keys($prices))->get()->keyBy('id');
        $units = $product->productUnits()->get()->keyBy('id');

        DB::transaction(function () use ($product, $prices, $lists, $units): void {
            foreach ($prices as $listId => $rows) {
                $list = $lists->get($listId);

                if (! $list || ! is_array($rows)) {
                    continue;
                }

                foreach ($rows as $unitId => $row) {
                    // وحدة حُذفت من جلسة اخرى لا يقع سعرها على الوحدة الاساسية.
                    if ((int) $unitId !== 0 && ! $units->has($unitId)) {
                        continue;
                    }

                    $this->save($product, $list, $units->get($unitId), is_array($row) ? $row : []);
                }
            }
        });
    }

    /**
     * @param  array{sell_price?: mixed, buy_price?: mixed, source_price_list_id?: mixed, source_factor?: mixed}  $data
     * @return ProductPrice|null null اذا فُرّغ الصف، فيُحذف
     */
    public function save(Product $product, PriceList $list, ?ProductUnit $unit, array $data): ?ProductPrice
    {
        if ($unit && (int) $unit->product_id !== (int) $product->id) {
            throw ValidationException::withMessages(['product_unit_id' => __('This unit does not belong to the item.')]);
        }

        $existing = ProductPrice::query()
            ->where('price_list_id', $list->id)
            ->where('product_id', $product->id)
            ->when(
                $unit,
                fn ($query) => $query->where('product_unit_id', $unit->id),
                fn ($query) => $query->whereNull('product_unit_id'),
            )
            ->first();

        if (blank($data['sell_price'] ?? null) && blank($data['buy_price'] ?? null) && blank($data['source_price_list_id'] ?? null)) {
            $existing?->delete();

            return null;
        }

        $sourceId = filled($data['source_price_list_id'] ?? null) ? (int) $data['source_price_list_id'] : null;

        if ($sourceId === (int) $list->id) {
            throw ValidationException::withMessages(['source_price_list_id' => __('A price list cannot take its prices from itself.')]);
        }

        if ($sourceId && ! PriceList::query()->whereKey($sourceId)->exists()) {
            throw ValidationException::withMessages(['source_price_list_id' => __('The selected source price list was not found.')]);
        }

        $attributes = [
            'sell_price' => $this->price($data['sell_price'] ?? null, 'sell_price'),
            'buy_price' => $this->price($data['buy_price'] ?? null, 'buy_price'),
            'source_price_list_id' => $sourceId,
            'source_factor' => $sourceId ? $this->sourceFactor($data['source_factor'] ?? null) : null,
        ];

        $price = $existing ?? new ProductPrice([
            'price_list_id' => $list->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit?->id,
            'created_by' => auth()->id(),
        ]);

        $price->fill($attributes);

        try {
            $price->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sell_price' => __('This item already has a price in this list for this unit.')]);
        }

        return $price;
    }

    private function price(mixed $value, string $field): string
    {
        try {
            $price = ExactDecimal::of(blank($value) ? '0' : $value, 'price')->toScale(ExactDecimal::MONEY_SCALE, RoundingMode::HalfUp);
        } catch (DomainException) {
            throw ValidationException::withMessages([$field => __('The price must be a number that is not negative.')]);
        }

        if ($price->isNegative()) {
            throw ValidationException::withMessages([$field => __('The price must be a number that is not negative.')]);
        }

        return (string) $price;
    }

    private function sourceFactor(mixed $value): string
    {
        try {
            $factor = ExactDecimal::of($value, 'source factor')->toScale(ExactDecimal::FACTOR_SCALE, RoundingMode::HalfUp);
        } catch (DomainException) {
            throw ValidationException::withMessages(['source_factor' => __('The source factor must be a number greater than zero.')]);
        }

        if (! $factor->isPositive()) {
            throw ValidationException::withMessages(['source_factor' => __('The source factor must be a number greater than zero.')]);
        }

        return (string) $factor;
    }
}
