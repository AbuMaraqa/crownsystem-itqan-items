<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Accounting\Http\Requests\Admin\Concerns\ValidatesItemsGrid;
use Modules\Accounting\Http\Requests\Admin\Concerns\ValidatesSalesServiceLines;

/**
 * فاتورة البيع: الرأس، وسطور جدول الاصناف (مع ربطها ببنود الطلبية وقطعها)، والخدمات. المبالغ
 * والاوزان وقاعدة الصنف الرئيسي يعيد حسابها SalesInvoiceService؛ هنا الشكل.
 */
class SalesInvoiceRequest extends FormRequest
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
            'sales_order_id' => ['nullable', 'integer', 'exists:'.$prefix.'sales_orders,id'],
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', 'exists:'.config('branch.table_prefix').'branches,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:'.$prefix.'warehouses,id'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'tax_id' => ['nullable', 'integer', 'exists:'.$prefix.'taxes,id'],
            'invoice_discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            ...$this->itemsGridRules(),
            'items.*.sales_order_item_id' => ['nullable', 'integer', 'exists:'.$prefix.'sales_order_items,id'],
            'items.*.pieces.*.sales_order_item_piece_id' => ['nullable', 'integer'],
            ...$this->servicesRules('sales_order_item_id', 'sales_order_items'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sales_order_id' => __('Sales Order'),
            'client_id' => __('Customer'),
            'warehouse_id' => __('Warehouse'),
            'invoice_date' => __('Invoice Date'),
            'due_date' => __('Due Date'),
            'currency_id' => __('Currency'),
            'tax_id' => __('Tax'),
            'invoice_discount' => __('Invoice Discount'),
            'notes' => __('Notes'),
            ...$this->itemsGridAttributes(),
            ...$this->servicesAttributes(),
        ];
    }

    /**
     * ما تقرؤه SalesInvoiceService::saveForm.
     *
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        return [
            ...$this->safe()->except(['items', 'services']),
            'items' => $this->gridItems(),
            'services' => $this->serviceLines(),
        ];
    }
}
