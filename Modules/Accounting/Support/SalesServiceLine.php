<?php

namespace Modules\Accounting\Support;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ItemType;
use Modules\Items\Enums\ServiceCostSource;
use Modules\Items\Enums\ServicePricingBasis;
use Modules\Items\Support\ProductSearch;

/**
 * حقول بند الخدمة في طلبية البيع وفاتورتها. نوع البند يؤخذ من بطاقة الصنف لا من
 * الواجهة، فلا تُسجَّل خدمة على انها سلعة او العكس.
 *
 * التكلفة: سعر تكلفة الوحدة × الكمية، وافتراضها من بطاقة الخدمة. الجهة المنفذة
 * تُحفظ للتكلفة الخارجية فقط؛ وبلا جهة مسجلة تُقيَّد التكلفة مصروفاً مستحقاً.
 */
final class SalesServiceLine
{
    /** ادوار من ينفّذ خدمة بتكلفة خارجية. */
    public const PROVIDER_ROLES = ['Supplier', 'Delivery Company', 'Shipping Company'];

    public static function isService(?Product $product): bool
    {
        return (bool) $product?->isService();
    }

    public static function defaultPricingBasis(?Product $product): string
    {
        return match (true) {
            (bool) $product?->isTransportService() => ServicePricingBasis::TRIP->value,
            $product?->pricing_type === 'weight' => ServicePricingBasis::WEIGHT->value,
            default => ServicePricingBasis::QUANTITY->value,
        };
    }

    public static function defaultCostSource(?Product $product): string
    {
        return ServiceCostSource::tryFrom((string) $product?->service_cost_source)?->value ?? ServiceCostSource::INTERNAL->value;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function fields(array $item, ?Product $product, float $quantity): array
    {
        if (! self::isService($product)) {
            return [
                'line_type' => ItemType::GOODS->value,
                'pricing_basis' => null,
                'unit_cost' => 0,
                'cost_total' => 0,
                'cost_source' => null,
                'service_provider_id' => null,
            ];
        }

        $pricingBasis = ServicePricingBasis::tryFrom((string) ($item['pricing_basis'] ?? ''))?->value ?? self::defaultPricingBasis($product);
        $costSource = ServiceCostSource::tryFrom((string) ($item['cost_source'] ?? ''))?->value ?? self::defaultCostSource($product);
        $unitCost = self::decimal($item['unit_cost'] ?? null, (float) ($product?->cost_price ?? 0));

        if ($unitCost < 0) {
            throw new DomainException(__('Service cost cannot be negative.'));
        }

        return [
            'line_type' => ItemType::SERVICE->value,
            'pricing_basis' => $pricingBasis,
            'unit_cost' => $unitCost,
            'cost_total' => round($quantity * $unitCost, 4),
            'cost_source' => $costSource,
            'service_provider_id' => $costSource === ServiceCostSource::EXTERNAL->value && filled($item['service_provider_id'] ?? null)
                ? (int) $item['service_provider_id']
                : null,
        ];
    }

    /**
     * يضم بنود الخدمات الى بنود المستند قبل الحفظ، فالخدمة تُخزَّن بنداً من بنود المستند بنوع
     * "خدمة". بند في قسم الخدمات صنفه ليس خدمة يُرفض (مكانه جدول الاصناف).
     *
     * @param  array<int|string, array<string, mixed>>  $items
     * @param  array<int|string, mixed>  $services
     * @return array<int, array<string, mixed>>
     *
     * @throws DomainException
     */
    public static function merge(array $items, array $services): array
    {
        $services = collect($services)
            ->filter(fn ($line): bool => is_array($line) && filled($line['product_id'] ?? null))
            ->values();

        $serviceIds = Product::query()
            ->whereIn('id', $services->pluck('product_id')->all())
            ->where('item_type', ItemType::SERVICE->value)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($services as $line) {
            if (! in_array((int) $line['product_id'], $serviceIds, true)) {
                throw new DomainException(__('":item" is not a service; add it in the items list.', [
                    'item' => ProductSearch::label($line['product_id']),
                ]));
            }
        }

        return array_merge(array_values($items), $services->all());
    }

    /**
     * بنود الخدمات من بنود مستند محفوظ، كما يعرضها قسم الخدمات.
     *
     * @param  Collection<int, Model>  $lines
     * @param  string|null  $sourceKey  عمود ربط البند ببند المستند المصدر (sales_quotation_item_id…)
     * @return array<int, array<string, mixed>>
     */
    public static function rows(Collection $lines, ?string $sourceKey = null): array
    {
        $services = $lines->filter(fn ($line): bool => $line->isService())->values();
        $labels = ProductSearch::labels($services->pluck('product_id')->all());

        return $services
            ->map(fn ($line): array => [
                '_key' => 'service-'.$line->id,
                'id' => $line->id,
                ...($sourceKey ? [$sourceKey => $line->{$sourceKey}] : []),
                'product_id' => $line->product_id,
                'product_label' => $labels[$line->product_id] ?? (string) $line->description,
                'description' => $line->description,
                'pricing_type' => 'simple',
                'pricing_basis' => $line->pricing_basis ?? ServicePricingBasis::QUANTITY->value,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_mode' => 'rate',
                'discount_rate' => $line->discount_rate,
                'discount_amount' => $line->discount_amount,
                'line_total' => $line->line_total,
                'cost_source' => $line->cost_source ?? ServiceCostSource::INTERNAL->value,
                'service_provider_id' => $line->service_provider_id,
                'service_provider_label' => $line->serviceProvider?->name,
                'unit_cost' => $line->unit_cost,
                'notes' => $line->notes,
            ])
            ->all();
    }

    /**
     * مواد العميل المحفوظة على الطلبية كما يعرضها قسمها.
     *
     * @param  Collection<int, Model>  $materials
     * @return array<int, array<string, mixed>>
     */
    public static function materialRows(Collection $materials): array
    {
        $labels = ProductSearch::labels($materials->pluck('product_id')->filter()->all());

        return $materials
            ->values()
            ->map(fn ($material): array => [
                '_key' => 'material-'.$material->id,
                'product_id' => $material->product_id,
                'product_label' => $material->product_id ? ($labels[$material->product_id] ?? '') : '',
                'description' => $material->description,
                'quantity' => $material->quantity,
                'weight' => $material->weight,
                'received_date' => $material->received_date?->toDateString(),
                'notes' => $material->notes,
            ])
            ->all();
    }

    /**
     * ما يأخذه بند الخدمة عند اختيارها: الوصف، اساس التسعير، السعر (من $unitPrice المسعّر للطرف)،
     * تكلفة الوحدة ومصدرها من بطاقة الخدمة. null لصنف ليس خدمة.
     *
     * @return array<string, mixed>|null
     */
    public static function defaults(mixed $productId, ?string $unitPrice = null): ?array
    {
        $product = filled($productId) ? Product::query()->with('translations')->find((int) $productId) : null;

        if (! self::isService($product)) {
            return null;
        }

        return [
            'id' => (int) $product->id,
            'label' => ProductSearch::label($product->id),
            'description' => $product->name,
            'pricing_basis' => self::defaultPricingBasis($product),
            'unit_price' => $unitPrice ?? number_format((float) ($product->sale_price ?: 0), 4, '.', ''),
            'unit_cost' => number_format((float) ($product->cost_price ?: 0), 4, '.', ''),
            'cost_source' => self::defaultCostSource($product),
        ];
    }

    /**
     * خيارات اساس التسعير (مع وحدة الكمية) ومصدر التكلفة لقسم الخدمات.
     *
     * @return array{pricing_bases: list<array{value: string, label: string, unit: string}>, cost_sources: list<array{value: string, label: string}>}
     */
    public static function options(): array
    {
        return [
            'pricing_bases' => array_map(fn (ServicePricingBasis $basis): array => [
                'value' => $basis->value,
                'label' => $basis->getLabel(),
                'unit' => $basis->unitLabel(),
            ], ServicePricingBasis::cases()),
            'cost_sources' => array_map(fn (ServiceCostSource $source): array => [
                'value' => $source->value,
                'label' => $source->getLabel(),
            ], ServiceCostSource::cases()),
        ];
    }

    /**
     * الجهات التي تنفذ الخدمة بتكلفة خارجية: موردون وشركات توصيل وشحن.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function providers(?string $search = null): array
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::PROVIDER_ROLES))
            ->when(filled($search), fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => (int) $user->id, 'label' => (string) $user->name])
            ->all();
    }

    private static function decimal(mixed $value, float $default): float
    {
        if ($value === null || $value === '') {
            return round($default, 4);
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        if (! is_numeric($value)) {
            throw new DomainException(__('Invalid numeric value.'));
        }

        return round((float) $value, 4);
    }
}
