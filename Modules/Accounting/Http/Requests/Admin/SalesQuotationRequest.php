<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Accounting\Http\Requests\Admin\Concerns\ValidatesItemsGrid;

/**
 * عرض سعر البيع: الرأس وسطور جدول الاصناف. المبالغ والاوزان وقاعدة الصنف الرئيسي واصنافه
 * الفرعية تعيد حسابها SalesQuotationService؛ هنا الشكل فقط.
 */
class SalesQuotationRequest extends FormRequest
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
        return [
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', 'exists:'.config('branch.table_prefix').'branches,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:'.config('accounting.table_prefix').'warehouses,id'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'tax_id' => ['nullable', 'integer', 'exists:'.config('accounting.table_prefix').'taxes,id'],
            'quotation_discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            ...$this->itemsGridRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'client_id' => __('Customer'),
            'warehouse_id' => __('Warehouse'),
            'quotation_date' => __('Quotation Date'),
            'valid_until' => __('Valid Until'),
            'currency_id' => __('Currency'),
            'tax_id' => __('Tax'),
            'quotation_discount' => __('Quotation Discount'),
            'notes' => __('Notes'),
            ...$this->itemsGridAttributes(),
        ];
    }
}
