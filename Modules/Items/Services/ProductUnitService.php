<?php

namespace Modules\Items\Services;

use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductUnit;
use Modules\Items\Entities\Unit;
use Modules\Items\Support\ExactDecimal;

/**
 * وحدات الصنف المكافئة. الوحدة الاساسية (products.unit_id) معاملها 1 ولا صف لها،
 * فلا تتكرر ولا يتكرر معاملها. لا معامل صفر او سالب، ولا تكرار لوحدة او معامل في الصنف.
 */
class ProductUnitService
{
    /**
     * @param  array{unit_id?: mixed, factor?: mixed, sort_order?: mixed}  $data
     */
    public function save(Product $product, array $data, ?ProductUnit $productUnit = null): ProductUnit
    {
        if (blank($product->unit_id)) {
            throw ValidationException::withMessages(['unit_id' => __('Set the base unit of the item first.')]);
        }

        $unitId = (int) ($data['unit_id'] ?? 0);

        if (! $unitId || ! Unit::query()->whereKey($unitId)->exists()) {
            throw ValidationException::withMessages(['unit_id' => __('Select a unit.')]);
        }

        if ($unitId === (int) $product->unit_id) {
            throw ValidationException::withMessages(['unit_id' => __('This is the base unit of the item; its factor is always 1.')]);
        }

        $factor = $this->factor($data['factor'] ?? null);

        $siblings = ProductUnit::query()
            ->where('product_id', $product->id)
            ->when($productUnit, fn ($query) => $query->whereKeyNot($productUnit->id));

        if ((clone $siblings)->where('unit_id', $unitId)->exists()) {
            throw ValidationException::withMessages(['unit_id' => __('This unit is already defined for the item.')]);
        }

        if ((clone $siblings)->where('factor', $factor)->exists()) {
            throw ValidationException::withMessages(['factor' => __('Another unit of this item has the same factor.')]);
        }

        $productUnit ??= new ProductUnit([
            'product_id' => $product->id,
            'created_by' => auth()->id(),
            'sort_order' => (int) ProductUnit::query()->where('product_id', $product->id)->max('sort_order') + 1,
        ]);

        $productUnit->fill(['unit_id' => $unitId, 'factor' => $factor]);

        if (filled($data['sort_order'] ?? null)) {
            $productUnit->sort_order = (int) $data['sort_order'];
        }

        try {
            DB::transaction(fn () => $productUnit->save());
        } catch (UniqueConstraintViolationException) {
            // طلبان متزامنان: القيد الفريد هو الحكم الاخير.
            throw ValidationException::withMessages(['factor' => __('Another unit of this item has the same factor.')]);
        }

        return $productUnit;
    }

    public function delete(ProductUnit $productUnit): void
    {
        $productUnit->delete();
    }

    /**
     * المعامل بدقة العمود، فلا تفوت قاعدة التكرار معاملين يتساويان بعد التقريب.
     */
    private function factor(mixed $value): string
    {
        try {
            $factor = ExactDecimal::of($value, 'unit factor')->toScale(ExactDecimal::FACTOR_SCALE, RoundingMode::HalfUp);
        } catch (DomainException) {
            throw ValidationException::withMessages(['factor' => __('The factor must be a number greater than zero.')]);
        }

        if (! $factor->isPositive()) {
            throw ValidationException::withMessages(['factor' => __('The factor must be a number greater than zero.')]);
        }

        if ($factor->isEqualTo(1)) {
            throw ValidationException::withMessages(['factor' => __('Factor 1 belongs to the base unit of the item.')]);
        }

        return (string) $factor;
    }
}
