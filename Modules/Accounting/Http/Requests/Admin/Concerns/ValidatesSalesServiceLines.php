<?php

namespace Modules\Accounting\Http\Requests\Admin\Concerns;

use Modules\Items\Enums\ServiceCostSource;
use Modules\Items\Enums\ServicePricingBasis;

/**
 * شكل قسمي الخدمات ومواد العميل (items-grid/ServiceLines.vue و CustomerMaterials.vue) في طلب
 * حفظ طلبية البيع او فاتورتها؛ التسعير والتكلفة والتحقق ان
 * البند خدمة في SalesServiceLine عند الحفظ.
 */
trait ValidatesSalesServiceLines
{
    /**
     * @param  string|null  $sourceKey  عمود ربط بند الخدمة ببند المستند المصدر، وجدوله
     * @return array<string, mixed>
     */
    protected function servicesRules(?string $sourceKey = null, ?string $sourceTable = null): array
    {
        $products = 'exists:'.config('items.table_prefix').'products,id';

        return [
            'services' => ['nullable', 'array'],
            'services.*.id' => ['nullable', 'integer'],
            ...($sourceKey ? ["services.*.{$sourceKey}" => ['nullable', 'integer', 'exists:'.config('accounting.table_prefix').$sourceTable.',id']] : []),
            'services.*.product_id' => ['nullable', 'integer', $products],
            'services.*.description' => ['nullable', 'string', 'max:255'],
            'services.*.quantity' => ['nullable', 'numeric', 'min:0.0001'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'services.*.pricing_basis' => ['nullable', 'in:'.implode(',', array_column(ServicePricingBasis::cases(), 'value'))],
            'services.*.discount_mode' => ['nullable', 'in:rate,amount'],
            'services.*.discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'services.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'services.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'services.*.cost_source' => ['nullable', 'in:'.implode(',', array_column(ServiceCostSource::cases(), 'value'))],
            'services.*.service_provider_id' => ['nullable', 'integer', 'exists:users,id'],
            'services.*.notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerMaterialsRules(): array
    {
        return [
            'customer_materials' => ['nullable', 'array'],
            'customer_materials.*.product_id' => ['nullable', 'integer', 'exists:'.config('items.table_prefix').'products,id'],
            'customer_materials.*.description' => ['nullable', 'string', 'max:255', 'required_without:customer_materials.*.product_id'],
            'customer_materials.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'customer_materials.*.weight' => ['nullable', 'numeric', 'min:0'],
            'customer_materials.*.received_date' => ['nullable', 'date'],
            'customer_materials.*.notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function servicesAttributes(): array
    {
        return [
            'services.*.product_id' => __('Service'),
            'services.*.quantity' => __('Quantity'),
            'services.*.unit_price' => __('Price'),
            'services.*.discount_rate' => __('Discount'),
            'services.*.discount_amount' => __('Discount Amount'),
            'services.*.unit_cost' => __('Unit Cost'),
            'services.*.cost_source' => __('Cost Source'),
            'services.*.service_provider_id' => __('Service Provider'),
            'customer_materials.*.product_id' => __('Item'),
            'customer_materials.*.description' => __('Description'),
            'customer_materials.*.quantity' => __('Quantity'),
            'customer_materials.*.weight' => __('Weight'),
            'customer_materials.*.received_date' => __('Received Date'),
        ];
    }

    /**
     * بنود الخدمات المختارة كما تقرؤها SalesServiceLine::merge.
     *
     * @return array<int, array<string, mixed>>
     */
    public function serviceLines(): array
    {
        return array_values(array_filter(
            (array) ($this->validated()['services'] ?? []),
            fn ($line): bool => is_array($line) && filled($line['product_id'] ?? null),
        ));
    }
}
