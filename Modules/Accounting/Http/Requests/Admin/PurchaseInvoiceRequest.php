<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Accounting\Http\Requests\Admin\Concerns\ValidatesItemsGrid;

/**
 * فاتورة الشراء: الرأس وسطور جدول الاصناف. سطر من طلبية يحمل purchase_order_item_id، وقطعه
 * purchase_order_item_piece_id. المبالغ والاوزان يعيد حسابها PurchaseInvoiceService.
 */
class PurchaseInvoiceRequest extends FormRequest
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
            'purchase_order_id' => ['nullable', 'integer', 'exists:'.$prefix.'purchase_orders,id'],
            'branch_id' => ['nullable', 'integer', 'exists:'.config('branch.table_prefix').'branches,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:'.$prefix.'warehouses,id'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'tax_id' => ['nullable', 'integer', 'exists:'.$prefix.'taxes,id'],
            'invoice_discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            ...$this->itemsGridRules(),
            'items.*.purchase_order_item_id' => ['nullable', 'integer', 'exists:'.$prefix.'purchase_order_items,id'],
            'items.*.pieces.*.purchase_order_item_piece_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'client_id' => __('Supplier'),
            'purchase_order_id' => __('Purchase Order'),
            'warehouse_id' => __('Warehouse'),
            'invoice_date' => __('Invoice Date'),
            'due_date' => __('Due Date'),
            'currency_id' => __('Currency'),
            'tax_id' => __('Tax'),
            'invoice_discount' => __('Invoice Discount'),
            'notes' => __('Notes'),
            ...$this->itemsGridAttributes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [...$this->validated(), 'items' => $this->gridItems()];
    }
}
