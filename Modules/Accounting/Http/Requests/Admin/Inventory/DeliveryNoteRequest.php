<?php

namespace Modules\Accounting\Http\Requests\Admin\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * سند التسليم من الشاشة: الرأس وسطور طلبية البيع بكمياتها ومواقع السحب. قواعد التسليم
 * (المتبقي، الرصيد، الشحن المباشر) في DeliveryNoteService.
 */
class DeliveryNoteRequest extends FormRequest
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
        return [
            'sales_order_id' => [$this->route('deliveryNote') ? 'nullable' : 'required', 'integer'],
            'warehouse_id' => ['required', 'integer', 'exists:'.config('accounting.table_prefix').'warehouses,id'],
            'delivery_date' => ['required', 'date'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'vehicle_no' => ['nullable', 'string', 'max:255'],
            'receiver_name' => ['nullable', 'string', 'max:255'],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.sales_order_item_id' => ['nullable', 'integer'],
            'items.*.sales_order_item_piece_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.location_id' => ['nullable', 'integer'],
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
            'id' => $item['id'] ?? null,
            'sales_order_item_id' => $item['sales_order_item_id'] ?? null,
            'sales_order_item_piece_id' => $item['sales_order_item_piece_id'] ?? null,
            'quantity' => $item['quantity'] ?? null,
            'location_id' => $item['location_id'] ?? null,
            'notes' => $item['notes'] ?? null,
        ], $data['items'] ?? []));

        return $data;
    }
}
