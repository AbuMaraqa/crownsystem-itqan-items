<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * طلبية تجميعية جديدة: التاريخان والبنود المسحوبة من طلبيات الشراء (كما بنتها نافذة السحب).
 * المبالغ والاوزان يعيد حسابها ConsolidatedPurchaseOrderService، والكميات تُقارن بالمتبقي.
 */
class ConsolidatedPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can` middleware decides
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $prefix = config('accounting.table_prefix');
        $products = 'exists:'.config('items.table_prefix').'products,id';

        return [
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.source_purchase_order_item_id' => ['required', 'integer', 'exists:'.$prefix.'purchase_order_items,id'],
            'items.*.product_id' => ['required', 'integer', $products],
            'items.*.description' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.actual_weight' => ['nullable', 'numeric', 'min:0'],
            'items.*.pricing_type' => ['nullable', 'in:simple,weight'],
            'items.*.pieces' => ['nullable', 'array'],
            'items.*.pieces.*.source_purchase_order_item_piece_id' => ['nullable', 'integer'],
            'items.*.pieces.*.product_id' => ['required', 'integer', $products],
            'items.*.pieces.*.barcode' => ['nullable', 'string', 'max:255'],
            'items.*.pieces.*.description' => ['nullable', 'string'],
            'items.*.pieces.*.piece_count' => ['nullable', 'numeric', 'min:0'],
            'items.*.pieces.*.piece_length' => ['nullable', 'numeric', 'min:0'],
            'items.*.pieces.*.weight_per_meter' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['items.required' => __('Please select at least one item to consolidate.'), 'items.min' => __('Please select at least one item to consolidate.')];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'order_date' => __('Order Date'),
            'expected_delivery_date' => __('Expected Delivery Date'),
            'items.*.quantity' => __('Quantity'),
        ];
    }
}
