<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Enums\PurchaseOrderDeliveryType;
use Modules\Accounting\Http\Requests\Admin\Concerns\ValidatesItemsGrid;

/**
 * طلبية الشراء: الرأس ونوع التوريد وسطور جدول الاصناف (بسعر اختياري) والمرفقات. المبالغ
 * والاوزان وقاعدة الصنف الرئيسي يعيد حسابها PurchaseOrderService؛ هنا الشكل فقط.
 */
class PurchaseOrderRequest extends FormRequest
{
    use ValidatesItemsGrid;

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

        return [
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', 'exists:'.config('branch.table_prefix').'branches,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:'.$prefix.'warehouses,id'],
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'delivery_type' => ['nullable', Rule::in(array_column(PurchaseOrderDeliveryType::cases(), 'value'))],
            'direct_customer_id' => ['nullable', 'required_if:delivery_type,'.PurchaseOrderDeliveryType::DIRECT->value, 'integer', 'exists:users,id'],
            'direct_sales_order_id' => ['nullable', 'integer', 'exists:'.$prefix.'sales_orders,id'],
            'notes' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'image', 'max:10240'],
            'removed_attachments' => ['nullable', 'array'],
            'removed_attachments.*' => ['integer'],
            'attachment_order' => ['nullable', 'array'],
            'attachment_order.*' => ['integer'],
            ...$this->itemsGridRules(),
            'items.*.source_purchase_order_item_id' => ['nullable', 'integer', 'exists:'.$prefix.'purchase_order_items,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'client_id' => __('Supplier'),
            'warehouse_id' => __('Warehouse'),
            'order_date' => __('Order Date'),
            'expected_delivery_date' => __('Expected Delivery Date'),
            'delivery_type' => __('Delivery Type'),
            'direct_customer_id' => __('Ship To Customer'),
            'direct_sales_order_id' => __('For Sales Order'),
            'notes' => __('Notes'),
            'attachments.*' => __('Attachments'),
            ...$this->itemsGridAttributes(),
        ];
    }

    /**
     * ما تقرؤه الخدمة: الرأس وسطور الجدول بلا الصفوف الفارغة ورؤوس المجموعات.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = collect($this->validated())->except(['attachments', 'removed_attachments', 'attachment_order'])->all();
        $data['items'] = $this->gridItems();

        return $data;
    }
}
