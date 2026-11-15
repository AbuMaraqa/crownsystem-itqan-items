<?php

namespace Modules\Accounting\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Enums\LaborRateBasis;
use Modules\Items\Enums\ServiceCostSource;
use Modules\Items\Enums\ServicePricingBasis;

/**
 * أمر الإنتاج: الرأس وبنود التكلفة الثلاثة (اصناف، أجور، خدمات). المجاميع وتكلفة الوحدة وقيمة
 * البيع يحسبها ProductionOrderService عند الحفظ؛ هنا الشكل فقط.
 */
class ProductionOrderRequest extends FormRequest
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
        $products = 'exists:'.config('items.table_prefix').'products,id';

        return [
            'sales_order_id' => ['nullable', 'integer', 'exists:'.config('accounting.table_prefix').'sales_orders,id'],
            'order_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer', 'exists:'.config('accounting.table_prefix').'warehouses,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'produced_quantity' => ['required', 'numeric', 'min:0.0001'],
            'notes' => ['nullable', 'string'],
            'materials' => ['nullable', 'array'],
            'materials.*.sales_order_item_id' => ['nullable', 'integer'],
            'materials.*.product_id' => ['nullable', 'integer', $products],
            'materials.*.description' => ['nullable', 'string', 'max:255', 'required_without:materials.*.product_id'],
            'materials.*.pricing_type' => ['nullable', Rule::in(['simple', 'weight'])],
            'materials.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'materials.*.weight' => ['nullable', 'numeric', 'min:0'],
            'materials.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'labor' => ['nullable', 'array'],
            'labor.*.worker_id' => ['nullable', 'integer', 'exists:users,id'],
            'labor.*.worker_name' => ['nullable', 'string', 'max:255'],
            'labor.*.description' => ['nullable', 'string', 'max:255'],
            'labor.*.rate_basis' => ['nullable', Rule::in(array_column(LaborRateBasis::cases(), 'value'))],
            'labor.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'labor.*.rate' => ['nullable', 'numeric', 'min:0'],
            'labor.*.work_date' => ['nullable', 'date'],
            'services' => ['nullable', 'array'],
            'services.*.product_id' => ['nullable', 'integer', $products],
            'services.*.description' => ['nullable', 'string', 'max:255', 'required_without:services.*.product_id'],
            'services.*.cost_source' => ['nullable', Rule::in(array_column(ServiceCostSource::cases(), 'value'))],
            'services.*.service_provider_id' => ['nullable', 'integer', 'exists:users,id'],
            'services.*.provider_reference' => ['nullable', 'string', 'max:255'],
            'services.*.pricing_basis' => ['nullable', Rule::in(array_column(ServicePricingBasis::cases(), 'value'))],
            'services.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'services.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'services.*.sent_date' => ['nullable', 'date'],
            'services.*.received_date' => ['nullable', 'date'],
            'services.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sales_order_id' => __('Sales Order'),
            'order_date' => __('Order Date'),
            'due_date' => __('Due Date'),
            'warehouse_id' => __('Warehouse'),
            'currency_id' => __('Currency'),
            'produced_quantity' => __('Produced Quantity'),
            'notes' => __('Notes'),
            'materials.*.product_id' => __('Item'),
            'materials.*.description' => __('Description'),
            'materials.*.quantity' => __('Quantity'),
            'materials.*.weight' => __('Weight'),
            'materials.*.unit_cost' => __('Unit Cost'),
            'labor.*.worker_id' => __('Worker'),
            'labor.*.quantity' => __('Quantity'),
            'labor.*.rate' => __('Wage Rate'),
            'labor.*.work_date' => __('Work Date'),
            'services.*.product_id' => __('Service'),
            'services.*.description' => __('Description'),
            'services.*.service_provider_id' => __('Service Provider'),
            'services.*.quantity' => __('Quantity'),
            'services.*.unit_cost' => __('Unit Cost'),
            'services.*.sent_date' => __('Sent Date'),
            'services.*.received_date' => __('Received Date'),
        ];
    }
}
