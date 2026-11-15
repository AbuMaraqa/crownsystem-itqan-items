<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * مرتجع الشراء. عند الانشاء: المورد وفاتورته المرحّلة والتاريخ والمخزن، وتؤخذ كل الكميات القابلة
 * للارجاع. في صفحة المرتجع: ما يُرجع من كل سطر (الكمية، او عدد قطع كل صنف فرعي) بحد اقصى
 * ما بقي؛ الطرف والعملة والضريبة والاسعار من الفاتورة، ويعيد حسابها PurchaseReturnService.
 */
class PurchaseReturnRequest extends FormRequest
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

        if ($this->routeIs('accounting.purchase-returns.store')) {
            return [
                'client_id' => ['required', 'integer', 'exists:users,id'],
                'purchase_invoice_id' => [
                    'required',
                    'integer',
                    Rule::exists($prefix.'purchase_invoices', 'id')->where(fn ($query) => $query->where('client_id', $this->input('client_id'))),
                ],
                'warehouse_id' => ['nullable', 'integer', 'exists:'.$prefix.'warehouses,id'],
                'return_date' => ['required', 'date'],
                'notes' => ['nullable', 'string'],
            ];
        }

        return [
            'warehouse_id' => ['nullable', 'integer', 'exists:'.$prefix.'warehouses,id'],
            'return_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.purchase_invoice_item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
            'items.*.pieces' => ['nullable', 'array'],
            'items.*.pieces.*.id' => ['nullable', 'integer'],
            'items.*.pieces.*.purchase_invoice_item_piece_id' => ['nullable', 'integer'],
            'items.*.pieces.*.piece_count' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'client_id' => __('Supplier'),
            'purchase_invoice_id' => __('Purchase Invoice'),
            'warehouse_id' => __('Warehouse'),
            'return_date' => __('Return Date'),
            'notes' => __('Notes'),
            'items.*.quantity' => __('Quantity'),
            'items.*.pieces.*.piece_count' => __('Piece Count'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        // المرتجع الجديد يأخذ كل ما بقي قابلاً للارجاع من الفاتورة (مع اصنافه الفرعية)، ويُعدَّل في صفحته.
        $data['items'] = $this->routeIs('accounting.purchase-returns.store')
            ? []
            : array_values(array_filter((array) ($data['items'] ?? []), fn ($item): bool => is_array($item)));

        return $data;
    }
}
