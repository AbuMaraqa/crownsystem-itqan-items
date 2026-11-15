<?php

namespace Modules\Accounting\Http\Requests\Admin\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Enums\StockAdjustmentType;

/**
 * رصيد افتتاحي / جرد / تسوية من الشاشة. الكمية في التسوية موجبة او سالبة، وفي الجرد العدد
 * الفعلي؛ الفرق والكلفة والقيد في StockAdjustmentService.
 */
class StockAdjustmentRequest extends FormRequest
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
            'type' => ['required', Rule::enum(StockAdjustmentType::class)],
            'warehouse_id' => ['required', 'integer', 'exists:'.config('accounting.table_prefix').'warehouses,id'],
            'adjustment_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['array'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.location_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric'],
            'items.*.counted_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        $data = $this->validated();

        // بلا كلفة تأخذ الخدمة متوسط الكلفة (الحقل يظهر لمن يرى قيمة المخزن فقط).
        $data['items'] = array_values(array_map(fn (array $item) => [
            'product_id' => $item['product_id'],
            'location_id' => $item['location_id'] ?? null,
            'quantity' => $item['quantity'] ?? null,
            'counted_quantity' => $item['counted_quantity'] ?? null,
            'unit_cost' => $item['unit_cost'] ?? null,
            'notes' => $item['notes'] ?? null,
        ], $data['items'] ?? []));

        return $data;
    }
}
