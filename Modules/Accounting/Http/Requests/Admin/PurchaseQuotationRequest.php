<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * عرض سعر الشراء: الرأس وسعر المورد لكل سطر من سطور طلبيته. السطور مفتاحها
 * purchase_order_item_id ولا تُضاف ولا تُحذف؛ المبالغ يعيد حسابها PurchaseQuotationService.
 */
class PurchaseQuotationRequest extends FormRequest
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

        return [
            'purchase_order_id' => [$this->routeIs('accounting.purchase-quotations.store') ? 'required' : 'nullable', 'integer', 'exists:'.$prefix.'purchase_orders,id'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date'],
            'supplier_reference' => ['nullable', 'string', 'max:255'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'tax_id' => ['nullable', 'integer', 'exists:'.$prefix.'taxes,id'],
            'quotation_discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'purchase_order_id' => __('Purchase Order'),
            'quotation_date' => __('Quotation Date'),
            'valid_until' => __('Valid Until'),
            'supplier_reference' => __('Supplier Quotation No'),
            'currency_id' => __('Currency'),
            'tax_id' => __('Tax'),
            'quotation_discount' => __('Quotation Discount'),
            'notes' => __('Notes'),
            'items.*.unit_price' => __('Price'),
            'items.*.discount_rate' => __('Discount'),
        ];
    }

    /**
     * ما تقرؤه الخدمة: الرأس، وسعر وخصم وملاحظة كل سطر من سطور الطلبية.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        $data['items'] = collect($data['items'] ?? [])
            ->filter(fn ($line): bool => is_array($line) && filled($line['purchase_order_item_id'] ?? null))
            ->map(fn (array $line): array => [
                'purchase_order_item_id' => (int) $line['purchase_order_item_id'],
                'unit_price' => $line['unit_price'] ?? 0,
                'discount_rate' => $line['discount_rate'] ?? 0,
                'notes' => $line['notes'] ?? null,
            ])
            ->values()
            ->all();

        return $data;
    }
}
