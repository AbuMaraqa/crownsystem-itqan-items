<?php

namespace Modules\Items\Support;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Enums\AccountType;
use Modules\Core\Entities\User;
use Modules\Items\Entities\ItemTypeProfile;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\Unit;
use Modules\Items\Enums\ItemType;
use Modules\Items\Enums\ProfileThickness;
use Modules\Items\Enums\ServiceCostSource;
use Modules\Items\Enums\ServiceKind;

/**
 * ما تحتاجه بطاقة الصنف في شجرة الاصناف (لوحة Vue) فوق قواعد ItemCardRules المشتركة:
 * ما يظهر من الحقول (الحقل المخفي لا يُحفظ فتبقى قيمته)، وقاعدة تكرار الاسم، وقوائم الاختيار.
 */
final class ItemCard
{
    public static function isWeight(array $state): bool
    {
        return ($state['pricing_type'] ?? null) === 'weight';
    }

    /**
     * المقطع: صنف وزن تحت صنف رئيسي.
     */
    public static function isPiece(array $state): bool
    {
        return self::isWeight($state) && filled($state['main_product_id'] ?? null);
    }

    public static function isService(array $state): bool
    {
        return ($state['item_type'] ?? null) === ItemType::SERVICE->value;
    }

    /**
     * الاسم غير مكرر الا للمقطع، كما في النظام القديم. الاسم الذي لم يتغير لا يُفحص، فما ورد
     * مكرراً من النظام القديم يبقى قابلاً للتعديل.
     */
    public static function duplicateNameError(mixed $name, ?Product $product, bool $isPiece): ?string
    {
        if ($isPiece || blank($name) || ($product && $product->name === $name)) {
            return null;
        }

        $duplicate = Product::query()
            ->whereNull('main_product_id')
            ->when($product, fn (Builder $query) => $query->whereKeyNot($product->id))
            ->whereHas('translations', fn (Builder $query) => $query->where('locale', app()->getLocale())->where('name', $name))
            ->exists();

        return $duplicate ? __('Another item has this name. Only pieces may repeat a name.') : null;
    }

    /**
     * قوائم الاختيار في البطاقة بالشكل [{value, label}].
     *
     * @return array<string, list<mixed>>
     */
    public static function options(?Product $product): array
    {
        $profileNames = ItemTypeProfile::options($product?->item_type_profile_id);
        $profiles = ItemTypeProfile::query()->whereKey(array_keys($profileNames))->get()->keyBy('id');

        return [
            'item_types' => self::list(ItemType::options()),
            'pricing_types' => self::list(['simple' => __('By Quantity'), 'weight' => __('By Weight')]),
            'service_kinds' => self::list(ServiceKind::options()),
            'cost_sources' => self::list(ServiceCostSource::options()),
            'revenue_accounts' => self::list(ItemCardRules::ledgerAccountOptions(AccountType::REVENUE)),
            'cost_accounts' => self::list(ItemCardRules::ledgerAccountOptions(AccountType::EXPENSE)),
            'units' => self::list(Unit::query()->with('translations')->get()
                ->mapWithKeys(fn (Unit $unit): array => [$unit->id => (string) $unit->name])
                ->all()),
            // نوع الالمنيوم يجعل الصنف بالوزن (القاعدة نفسها في Product عند الحفظ).
            'profiles' => collect($profileNames)
                ->map(fn (string $name, int $id): array => ['value' => $id, 'label' => $name, 'aluminium' => (bool) $profiles->get($id)?->isAluminium()])
                ->values()
                ->all(),
            'thicknesses' => self::list(ProfileThickness::options()),
            'profile_shapes' => [['value' => 1, 'label' => __('Open')], ['value' => 0, 'label' => __('Closed')]],
            'suppliers' => self::list(User::query()->supplierRole()->orderBy('name')->pluck('name', 'id')->all()),
            'colors' => array_values(Product::colorOptions()),
        ];
    }

    /**
     * @param  array<int|string, string>  $map
     * @return list<array{value: int|string, label: string}>
     */
    private static function list(array $map): array
    {
        $options = [];

        foreach ($map as $value => $label) {
            $options[] = ['value' => $value, 'label' => (string) $label];
        }

        return $options;
    }
}
