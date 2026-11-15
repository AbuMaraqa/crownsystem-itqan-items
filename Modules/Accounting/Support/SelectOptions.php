<?php

namespace Modules\Accounting\Support;

use Modules\Accounting\Entities\Account;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Currency;

/**
 * قوائم الاختيار في لوحة Vue بالشكل الذي تقرؤه SelectInput وMultiSelect: [{value, label}].
 * المصدر يبقى واحداً (options() في التعداد او النموذج)، وهذا الصنف يغيّر الشكل فقط.
 */
final class SelectOptions
{
    /**
     * @param  array<int|string, string>  $map  value => label (مثل Enum::options())
     * @return list<array{value: int|string, label: string}>
     */
    public static function from(array $map): array
    {
        $options = [];

        foreach ($map as $value => $label) {
            $options[] = ['value' => $value, 'label' => (string) $label];
        }

        return $options;
    }

    /**
     * الحسابات بترتيب الشجرة (Account::options)، والطرفية فقط عند $postableOnly.
     *
     * @return list<array{value: int|string, label: string}>
     */
    public static function accounts(bool $postableOnly = false): array
    {
        return self::from(Account::options($postableOnly));
    }

    /**
     * @return list<array{value: int|string, label: string}>
     */
    public static function branches(): array
    {
        return self::from(Branch::query()->with('translations')->get()
            ->mapWithKeys(fn (Branch $branch) => [$branch->id => (string) $branch->name])
            ->all());
    }

    /**
     * @return list<array{value: int|string, label: string}>
     */
    public static function currencies(): array
    {
        return self::from(Currency::query()->orderBy('code')->pluck('code', 'id')->all());
    }
}
