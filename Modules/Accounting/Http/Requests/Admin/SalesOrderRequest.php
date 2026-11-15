<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Enums\SalesOrderFulfillmentType;
use Modules\Accounting\Http\Requests\Admin\Concerns\ValidatesItemsGrid;
use Modules\Accounting\Http\Requests\Admin\Concerns\ValidatesSalesServiceLines;

/**
 * طلبية البيع: الرأس ومصدر التوريد، وسطور جدول الاصناف (مع ربطها ببنود عرض السعر)، والخدمات
 * ومواد العميل. المبالغ والاوزان وقاعدة الصنف الرئيسي يعيد حسابها SalesOrderService؛ هنا الشكل.
 */
class SalesOrderRequest extends FormRequest
{
    use ValidatesItemsGrid;
    use ValidatesSalesServiceLines;

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
            'sales_quotation_id' => ['nullable', 'integer', Rule::exists($prefix.'sales_quotations', 'id')],
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', 'exists:'.config('branch.table_prefix').'branches,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:'.$prefix.'warehouses,id'],
            'fulfillment_type' => ['nullable', Rule::in(array_column(SalesOrderFulfillmentType::cases(), 'value'))],
            'direct_supplier_id' => ['nullable', 'integer', 'exists:users,id'],
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'tax_id' => ['nullable', 'integer', 'exists:'.$prefix.'taxes,id'],
            'order_discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            ...$this->itemsGridRules(),
            'items.*.sales_quotation_item_id' => ['nullable', 'integer', 'exists:'.$prefix.'sales_quotation_items,id'],
            ...$this->servicesRules('sales_quotation_item_id', 'sales_quotation_items'),
            ...$this->customerMaterialsRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sales_quotation_id' => __('Sales Quotation'),
            'client_id' => __('Customer'),
            'warehouse_id' => __('Warehouse'),
            'fulfillment_type' => __('Fulfillment Source'),
            'direct_supplier_id' => __('Suggested Supplier'),
            'order_date' => __('Order Date'),
            'expected_delivery_date' => __('Expected Delivery Date'),
            'currency_id' => __('Currency'),
            'tax_id' => __('Tax'),
            'order_discount' => __('Order Discount'),
            'notes' => __('Notes'),
            ...$this->itemsGridAttributes(),
            ...$this->servicesAttributes(),
        ];
    }

    /**
     * ما تقرؤه SalesOrderService::saveForm.
     *
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        return [
            ...$this->safe()->except(['items', 'services', 'customer_materials']),
            'items' => $this->gridItems(),
            'services' => $this->serviceLines(),
            'customer_materials' => array_values((array) ($this->validated()['customer_materials'] ?? [])),
        ];
    }
}
