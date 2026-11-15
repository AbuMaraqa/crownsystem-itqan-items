<?php

namespace Modules\Accounting\Http\Requests\Admin\Concerns;

/**
 * شكل سطور جدول الاصناف (Pages/parts/items-grid) في طلب حفظ مستند: صنف السطر وكميته ووزنه
 * الفعلي وسعره وخصمه، وقطعه تحته.
 */
trait ValidatesItemsGrid
{
    /**
     * @return array<string, mixed>
     */
    protected function itemsGridRules(string $key = 'items'): array
    {
        $products = 'exists:'.config('items.table_prefix').'products,id';

        return [
            $key => ['nullable', 'array'],
            "{$key}.*.id" => ['nullable', 'integer'],
            "{$key}.*.product_id" => ['nullable', 'integer', $products],
            "{$key}.*.category_id" => ['nullable', 'integer', 'exists:'.config('items.table_prefix').'categories,id'],
            "{$key}.*.quantity" => ['nullable', 'numeric', 'min:0.0001'],
            "{$key}.*.length" => ['nullable', 'numeric', 'min:0'],
            "{$key}.*.unit_price" => ['nullable', 'numeric', 'min:0'],
            "{$key}.*.actual_weight" => ['nullable', 'numeric', 'min:0'],
            "{$key}.*.discount_mode" => ['nullable', 'in:rate,amount'],
            "{$key}.*.discount_rate" => ['nullable', 'numeric', 'min:0', 'max:100'],
            "{$key}.*.discount_amount" => ['nullable', 'numeric', 'min:0'],
            "{$key}.*.barcode" => ['nullable', 'string', 'max:255'],
            "{$key}.*.description" => ['nullable', 'string'],
            "{$key}.*.notes" => ['nullable', 'string'],
            "{$key}.*.product_label" => ['nullable', 'string'],
            "{$key}.*.pieces" => ['nullable', 'array'],
            "{$key}.*.pieces.*.id" => ['nullable', 'integer'],
            "{$key}.*.pieces.*.product_id" => ['nullable', 'integer', $products],
            "{$key}.*.pieces.*.source_product_id" => ['nullable', 'integer', $products],
            "{$key}.*.pieces.*.piece_count" => ['nullable', 'numeric', 'min:0'],
            "{$key}.*.pieces.*.piece_length" => ['nullable', 'numeric', 'min:0'],
            "{$key}.*.pieces.*.weight_per_meter" => ['nullable', 'numeric', 'min:0'],
            "{$key}.*.pieces.*.barcode" => ['nullable', 'string', 'max:255'],
            "{$key}.*.pieces.*.description" => ['nullable', 'string'],
            "{$key}.*.pieces.*.product_label" => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function itemsGridAttributes(string $key = 'items'): array
    {
        return [
            "{$key}.*.product_id" => __('Item'),
            "{$key}.*.quantity" => __('Quantity'),
            "{$key}.*.unit_price" => __('Price'),
            "{$key}.*.actual_weight" => __('Actual Weight'),
            "{$key}.*.discount_rate" => __('Discount'),
            "{$key}.*.discount_amount" => __('Discount Amount'),
            "{$key}.*.pieces.*.product_id" => __('Sub Items'),
            "{$key}.*.pieces.*.piece_count" => __('Piece Count'),
            "{$key}.*.pieces.*.piece_length" => __('Piece Length'),
            "{$key}.*.pieces.*.weight_per_meter" => __('Weight per Meter'),
        ];
    }

    /**
     * سطور الجدول بلا الصفوف الفارغة ورؤوس المجموعات، كما تقرؤها خدمات المستندات.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gridItems(string $key = 'items'): array
    {
        return array_values(array_filter(
            (array) ($this->validated()[$key] ?? []),
            fn ($item): bool => is_array($item) && filled($item['product_id'] ?? null),
        ));
    }
}
