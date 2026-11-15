<?php

namespace Modules\Accounting\Http\Requests\Admin\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * التحويل المخزني من الشاشة: المخزنان والتاريخ وسطور الاصناف بكمياتها ومواقعها. القواعد
 * (رصيد المخزن المرسل، النقل داخل نفس المخزن) في StockTransferService.
 */
class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $warehouses = 'exists:'.config('accounting.table_prefix').'warehouses,id';

        return [
            'from_warehouse_id' => ['required', 'integer', $warehouses],
            'to_warehouse_id' => ['required', 'integer', $warehouses],
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['array'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.from_location_id' => ['nullable', 'integer'],
            'items.*.to_location_id' => ['nullable', 'integer'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        $data = $this->validated();
        $data['items'] = array_values(array_map(fn (array $item) => [
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'] ?? null,
            'from_location_id' => $item['from_location_id'] ?? null,
            'to_location_id' => $item['to_location_id'] ?? null,
            'notes' => $item['notes'] ?? null,
        ], $data['items'] ?? []));

        return $data;
    }
}
