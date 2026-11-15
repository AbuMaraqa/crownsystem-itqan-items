<?php

namespace Modules\Items\Support;

use Modules\Accounting\Entities\Account;
use Modules\Accounting\Enums\AccountType;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ItemType;
use Modules\Items\Enums\ServiceCostSource;
use Modules\Items\Enums\ServiceKind;

/**
 * قواعد بطاقة الصنف في مكان واحد: تستخدمها بطاقة شجرة الاصناف (ItemTreeCardRequest و ItemCardService)
 * وصفحة الصنف (ProductRequest و ProductService)، فلا تختلف القاعدة بين الشاشتين.
 *
 * - طبيعة الصنف (سلعة او خدمة) وما يتبعها من حقول.
 * - حقول نوع الصنف ومقطع الالمنيوم.
 * - الصنف الرئيسي واصنافه الفرعية (شجرة بمستوى واحد، والكل صنف وزن).
 */
final class ItemCardRules
{
    public const ITEM_MODEL_FIELDS = ['item_type_profile_id', 'bundle_count', 'profile_thickness', 'is_open_profile', 'area'];

    /**
     * حقول طبيعة الصنف عند الحفظ. الخدمة لا تتبع صنفاً رئيسياً ولا تدار في المخزون،
     * والسلعة لا تحمل اعدادات الخدمة.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function itemTypeAttributes(array $state): array
    {
        $isService = ($state['item_type'] ?? null) === ItemType::SERVICE->value;

        return [
            'item_type' => $isService ? ItemType::SERVICE->value : ItemType::GOODS->value,
            'service_kind' => $isService ? (ServiceKind::tryFrom((string) ($state['service_kind'] ?? ''))?->value ?? ServiceKind::GENERAL->value) : null,
            'service_cost_source' => $isService ? (ServiceCostSource::tryFrom((string) ($state['service_cost_source'] ?? ''))?->value ?? ServiceCostSource::INTERNAL->value) : null,
            'revenue_account_id' => $isService ? ($state['revenue_account_id'] ?? null) : null,
            'cost_account_id' => $isService ? ($state['cost_account_id'] ?? null) : null,
            'main_product_id' => $isService ? null : ($state['main_product_id'] ?? null),
            // متابعة المخزون تتبع طبيعة الصنف: السلعة تُتابَع دائماً والخدمة لا.
            'manage_stock' => $isService ? 0 : 1,
        ];
    }

    /**
     * قيم حقول نوع الصنف والمقطع عند الحفظ. الحقل الذي لم يصل تبقى قيمته المحفوظة، والمفرَّغ
     * يصبح null (النص الفارغ يكسر الاعمدة الرقمية وتحويل السماكة، ويُقرأ في is_open_profile "مغلق").
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function itemModelAttributes(array $state): array
    {
        $attributes = [];

        foreach (self::ITEM_MODEL_FIELDS as $field) {
            if (array_key_exists($field, $state)) {
                $attributes[$field] = blank($state[$field]) ? null : $state[$field];
            }
        }

        return $attributes;
    }

    /**
     * قيم حقول نوع الصنف والمقطع عند فتح النموذج.
     *
     * @return array<string, mixed>
     */
    public static function itemModelFormState(Product $product): array
    {
        return [
            'item_type_profile_id' => $product->item_type_profile_id,
            'bundle_count' => $product->bundle_count,
            'profile_thickness' => $product->profile_thickness?->value,
            'is_open_profile' => $product->is_open_profile === null ? null : (int) $product->is_open_profile,
            'area' => $product->area,
        ];
    }

    /**
     * خطأ اختيار الصنف الرئيسي، او null. كل ما يدخل تحت صنف رئيسي صنف وزن، والصنف الرئيسي
     * صنف وزن ليس فرعياً لغيره، والصنف الذي تحته فروع لا يدخل تحت صنف آخر.
     */
    public static function mainProductError(mixed $value, ?string $pricingType, ?int $productId): ?string
    {
        if (blank($value)) {
            return null;
        }

        if ($pricingType !== 'weight') {
            return __('A sub-item must be priced by weight.');
        }

        if ($productId !== null && (int) $value === $productId) {
            return __('An item cannot be its own main item.');
        }

        $main = Product::query()->select(['id', 'main_product_id', 'pricing_type'])->find($value);

        if (! $main) {
            return __('The selected main item was not found.');
        }

        if ($main->pricing_type !== 'weight') {
            // سعر الكيلوغرام يؤخذ من الصنف الرئيسي.
            return __('The main item must be priced by weight.');
        }

        if ($main->main_product_id) {
            return __('The selected item is itself a sub-item and cannot be a main item.');
        }

        if ($productId !== null && Product::query()->where('main_product_id', $productId)->exists()) {
            return __('This item has sub-items, so it cannot be placed under another main item.');
        }

        return null;
    }

    /**
     * الصنف الرئيسي الذي تحته اصناف فرعية يبقى صنف وزن؛ تحويله الى "بالكمية" يترك فروعه
     * تحت صنف غير موزون فيتعذر حفظها بعد ذلك.
     */
    public static function weightPricingError(mixed $value, ?int $productId): ?string
    {
        if ($value !== 'weight' && $productId !== null && Product::query()->where('main_product_id', $productId)->exists()) {
            return __('This item has sub-items, so it must stay priced by weight.');
        }

        return null;
    }

    /**
     * حسابات الايراد او التكلفة التي تُختار للخدمة (حسابات ترحيل نشطة).
     *
     * @return array<int, string>
     */
    public static function ledgerAccountOptions(AccountType $type): array
    {
        if (! class_exists(Account::class)) {
            return [];
        }

        return Account::query()
            ->with('translations')
            ->postable()
            ->active()
            ->where('type', $type->value())
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $account): array => [$account->id => $account->code.' - '.$account->name])
            ->all();
    }
}
