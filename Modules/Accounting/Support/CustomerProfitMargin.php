<?php

namespace Modules\Accounting\Support;

use Modules\Accounting\Entities\CustomerGroup;
use Modules\Core\Entities\User;

/**
 * نسبة ربح الزبون عند التسعير: نسبته الخاصة ان وُجدت، والا نسبة تصنيفه المفعّل،
 * والا فلا نسبة ويبقى سعر البيع المعتاد للصنف.
 */
final class CustomerProfitMargin
{
    public static function for(mixed $clientId): ?float
    {
        return self::resolve($clientId)['margin'];
    }

    /**
     * سطر توضيحي تحت حقل الزبون في مستندات البيع.
     */
    public static function hint(mixed $clientId): ?string
    {
        ['margin' => $margin, 'group' => $group] = self::resolve($clientId);

        if ($margin === null) {
            return blank($clientId) ? null : __('No profit margin for this client; items use their sale price.');
        }

        return $group
            ? __('Profit margin :margin% (customer group: :group) added to the cost price.', ['margin' => CustomerGroup::formatMargin($margin), 'group' => $group])
            : __('Profit margin :margin% added to the cost price.', ['margin' => CustomerGroup::formatMargin($margin)]);
    }

    /**
     * @return array{margin: ?float, group: ?string}
     */
    private static function resolve(mixed $clientId): array
    {
        if (blank($clientId)) {
            return ['margin' => null, 'group' => null];
        }

        $client = User::query()->with('customerGroup')->whereKey($clientId)->first(['id', 'customer_group_id', 'profit_margin']);

        if (! $client) {
            return ['margin' => null, 'group' => null];
        }

        if ($client->profit_margin !== null) {
            return ['margin' => (float) $client->profit_margin, 'group' => null];
        }

        $group = $client->customerGroup;

        if ($group && $group->is_active) {
            return ['margin' => (float) $group->profit_margin, 'group' => $group->name];
        }

        return ['margin' => null, 'group' => null];
    }
}
