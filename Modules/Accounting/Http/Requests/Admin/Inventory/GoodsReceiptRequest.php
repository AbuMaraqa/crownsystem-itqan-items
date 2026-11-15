<?php

namespace Modules\Accounting\Http\Requests\Admin\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * سند الاستلام من الشاشة: الرأس وسطور الطلبية بكمياتها ومواقعها. قواعد الاستلام (المتبقي،
 * انتماء السطر للطلبية، حالة الطلبية) في GoodsReceiptService.
 */
class GoodsReceiptRequest extends FormRequest
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
            'purchase_order_id' => [$this->route('goodsReceipt') ? 'nullable' : 'required', 'integer'],
            'warehouse_id' => ['required', 'integer', 'exists:'.config('accounting.table_prefix').'warehouses,id'],
            'receipt_date' => ['required', 'date'],
            'supplier_document_no' => ['nullable', 'string', 'max:255'],
            'container_no' => ['nullable', 'string', 'max:255'],
            'received_by_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.purchase_order_item_piece_id' => ['nullable', 'integer'],
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
            'purchase_order_item_id' => $item['purchase_order_item_id'] ?? null,
            'purchase_order_item_piece_id' => $item['purchase_order_item_piece_id'] ?? null,
            'quantity' => $item['quantity'] ?? null,
            'location_id' => $item['location_id'] ?? null,
            'notes' => $item['notes'] ?? null,
        ], $data['items'] ?? []));

        return $data;
    }
}
