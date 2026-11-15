<?php

namespace Modules\Items\Services;

use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;
use Modules\Items\Entities\ProductUnit;
use Modules\Items\Support\ExactDecimal;

/**
 * الباركودات الاضافية للصنف. الباركود يجوز تكراره بين الاصناف (المقطع يتكرر تحت كل
 * لون)، ولا يتكرر داخل الصنف الواحد ولا يساوي باركوده الرئيسي. النجمة في بدايته تعني
 * البادئة كما في النظام القديم، فتُنزع عند الحفظ.
 */
class ProductBarcodeService
{
    private const MAX_LENGTH = 255;

    /**
     * @param  array{barcode?: mixed, product_unit_id?: mixed, pack_quantity?: mixed, is_prefix?: mixed, sort_order?: mixed}  $data
     */
    public function save(Product $product, array $data, ?ProductBarcode $row = null): ProductBarcode
    {
        $raw = trim((string) ($data['barcode'] ?? ''));
        $isPrefix = str_starts_with($raw, '*') || filter_var($data['is_prefix'] ?? false, FILTER_VALIDATE_BOOL);
        $barcode = ltrim($raw, '*');

        if ($barcode === '' || mb_strlen($barcode) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['barcode' => __('Enter a valid barcode.')]);
        }

        if ($this->isOnItem($product, $barcode, $row?->id)) {
            throw ValidationException::withMessages(['barcode' => __('This item already has this barcode.')]);
        }

        $productUnitId = filled($data['product_unit_id'] ?? null) ? (int) $data['product_unit_id'] : null;

        if ($productUnitId && ! ProductUnit::query()->whereKey($productUnitId)->where('product_id', $product->id)->exists()) {
            throw ValidationException::withMessages(['product_unit_id' => __('This unit does not belong to the item.')]);
        }

        $row ??= new ProductBarcode([
            'product_id' => $product->id,
            'created_by' => auth()->id(),
            'sort_order' => (int) ProductBarcode::query()->where('product_id', $product->id)->max('sort_order') + 1,
        ]);

        $row->fill([
            'barcode' => $barcode,
            'product_unit_id' => $productUnitId,
            'pack_quantity' => $this->packQuantity($data['pack_quantity'] ?? null),
            'is_prefix' => $isPrefix,
        ]);

        if (filled($data['sort_order'] ?? null)) {
            $row->sort_order = (int) $data['sort_order'];
        }

        try {
            DB::transaction(fn () => $row->save());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['barcode' => __('This item already has this barcode.')]);
        }

        return $row;
    }

    public function delete(ProductBarcode $row): void
    {
        $row->delete();
    }

    /**
     * عدد الاصناف الاخرى التي تحمل الباركود نفسه (رئيسياً او اضافياً)، لتنبيه لا يمنع الحفظ.
     */
    public function otherItemsCount(Product $product, string $barcode): int
    {
        $barcode = ltrim(trim($barcode), '*');

        return Product::query()
            ->whereKeyNot($product->id)
            ->where(fn ($query) => $query
                ->where('barcode', $barcode)
                ->orWhereHas('productBarcodes', fn ($rows) => $rows->where('barcode', $barcode)))
            ->count();
    }

    private function isOnItem(Product $product, string $barcode, ?int $ignoreRowId): bool
    {
        // الباركود الرئيسي للصنف نفسه يغلب عند القراءة، فتكراره اضافياً لا يصل اليه احد.
        return Product::query()->whereKey($product->id)->where('barcode', $barcode)->exists()
            || ProductBarcode::query()
                ->where('product_id', $product->id)
                ->where('barcode', $barcode)
                ->when($ignoreRowId, fn ($query) => $query->whereKeyNot($ignoreRowId))
                ->exists();
    }

    private function packQuantity(mixed $value): string
    {
        if (blank($value)) {
            return '1.000000';
        }

        try {
            $quantity = ExactDecimal::of($value, 'pack quantity')->toScale(ExactDecimal::FACTOR_SCALE, RoundingMode::HalfUp);
        } catch (DomainException) {
            throw ValidationException::withMessages(['pack_quantity' => __('The pack quantity must be a number greater than zero.')]);
        }

        if (! $quantity->isPositive()) {
            throw ValidationException::withMessages(['pack_quantity' => __('The pack quantity must be a number greater than zero.')]);
        }

        return (string) $quantity;
    }
}
