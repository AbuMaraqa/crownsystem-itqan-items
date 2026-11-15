<?php

namespace Modules\Accounting\Support;

use Modules\Items\Entities\Product;

/**
 * قواعد حدود الصنف الثلاثة، في مكان واحد تستخدمه كل نماذج الصنف:
 * - الحد الاعلى (يكفي 6 اشهر) > الحد الادنى 1 (3 اشهر) > الحد الادنى 2 (شهر) > 0.
 * - او الثلاثة صفر = صنف غير مراقَب (لا متابعة ولا اشعارات ولا اقتراح طلب).
 * - لا يُقبل ان يكون بعضها صفراً وبعضها لا.
 */
class StockLimits
{
    public const FIELDS = ['max_limit', 'min_limit_1', 'min_limit_2'];

    public const PERMISSION = 'Stock Limit Update';

    /**
     * الصنف مراقَب ما دام احد حدوده اكبر من صفر.
     */
    public static function isMonitored(Product $product): bool
    {
        return (float) $product->max_limit > 0 || (float) $product->min_limit_1 > 0 || (float) $product->min_limit_2 > 0;
    }

    public static function canEdit(): bool
    {
        return auth()->user()?->can(self::PERMISSION) ?? false;
    }

    /**
     * اخطاء الحدود لكل حقل (فارغ = صحيحة).
     *
     * @return array<string, string>
     */
    public static function errors(mixed $max, mixed $min1, mixed $min2): array
    {
        $values = ['max_limit' => (float) $max, 'min_limit_1' => (float) $min1, 'min_limit_2' => (float) $min2];
        $errors = [];

        foreach ($values as $field => $value) {
            if ($value < 0) {
                $errors[$field] = __('Stock limits cannot be negative.');
            }
        }

        $filled = array_filter($values, fn (float $value) => $value > 0);

        if ($errors !== [] || $filled === []) {
            return $errors;
        }

        if (count($filled) < 3) {
            foreach (array_diff_key($values, $filled) as $field => $value) {
                $errors[$field] = __('Enter all three limits, or set all of them to zero to stop monitoring the item.');
            }

            return $errors;
        }

        if ($values['min_limit_1'] >= $values['max_limit']) {
            $errors['min_limit_1'] = __('Min Limit 1 must be less than the Max Limit.');
        }

        if ($values['min_limit_2'] >= $values['min_limit_1']) {
            $errors['min_limit_2'] = __('Min Limit 2 must be less than Min Limit 1.');
        }

        return $errors;
    }

    /**
     * هل تغيّر اي حد؟ المقارنة رقمية ("16" = "16.0000").
     */
    public static function changed(array $old, array $new): bool
    {
        foreach (self::FIELDS as $field) {
            if (abs((float) ($old[$field] ?? 0) - (float) ($new[$field] ?? 0)) > 0.00005) {
                return true;
            }
        }

        return false;
    }
}
