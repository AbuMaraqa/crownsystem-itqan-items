<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * مرتجع البيع. الانشاء: الزبون وفاتورته المرحّلة وتاريخ المرتجع (يأخذ كل القابل للارجاع). الحفظ:
 * التاريخ والمخزن والملاحظات، وما يُرجع من كل سطر (الكمية او عدد قطع كل صنف فرعي). الحدود
 * والاسعار يحسبها SalesReturnService (ReturnPieces)؛ الصفر يعني ان السطر لا يُرجع.
 */
class SalesReturnRequest extends FormRequest
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
        $common = [
            'warehouse_id' => ['nullable', 'integer', 'exists:'.$prefix.'warehouses,id'],
            'return_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];

        if ($this->creating()) {
            return [
                ...$common,
                'client_id' => ['required', 'integer', 'exists:users,id'],
                'sales_invoice_id' => [
                    'required',
                    'integer',
                    Rule::exists($prefix.'sales_invoices', 'id')->where('client_id', $this->input('client_id')),
                ],
            ];
        }

        return [
            ...$common,
            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.sales_invoice_item_id' => ['nullable', 'integer', 'exists:'.$prefix.'sales_invoice_items,id'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
            'items.*.pieces' => ['nullable', 'array'],
            'items.*.pieces.*.id' => ['nullable', 'integer'],
            'items.*.pieces.*.sales_invoice_item_piece_id' => ['nullable', 'integer'],
            'items.*.pieces.*.piece_count' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'client_id' => __('Customer'),
            'sales_invoice_id' => __('Sales Invoice'),
            'warehouse_id' => __('Warehouse'),
            'return_date' => __('Return Date'),
            'notes' => __('Notes'),
            'items.*.quantity' => __('Quantity'),
            'items.*.pieces.*.piece_count' => __('Piece Count'),
        ];
    }

    /**
     * ما تقرؤه SalesReturnService. المرتجع الجديد بلا سطور يأخذ كل ما بقي قابلاً للارجاع.
     *
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        $data = $this->validated();

        $data['items'] = $this->creating() ? [] : array_values(array_filter(
            (array) ($data['items'] ?? []),
            fn ($item): bool => is_array($item) && filled($item['sales_invoice_item_id'] ?? null),
        ));

        return $data;
    }

    private function creating(): bool
    {
        return $this->routeIs('accounting.sales-returns.store');
    }
}
