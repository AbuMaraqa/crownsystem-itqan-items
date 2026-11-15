<?php

namespace Modules\Accounting\Support;

use Modules\Core\Entities\User;
use Modules\Items\Entities\PriceList;

/**
 * اعدادات تسعير سطور المستندات لطرف: قيمته الخاصة ان وُجدت، والا قيمة تصنيفه
 * المفعّل، كما في CustomerProfitMargin.
 */
final class PartyPricing
{
    /**
     * @return array{use_last_price: bool, price_list: ?PriceList}
     */
    public static function for(int $partyId): array
    {
        $party = User::query()->with('customerGroup')->whereKey($partyId)->first(['id', 'customer_group_id', 'use_last_price', 'price_list_id']);

        if (! $party) {
            return ['use_last_price' => false, 'price_list' => null];
        }

        $group = $party->customerGroup?->is_active ? $party->customerGroup : null;
        $listId = $party->price_list_id ?? $group?->price_list_id;

        return [
            'use_last_price' => (bool) ($party->use_last_price ?? $group?->use_last_price ?? false),
            // القائمة الموقوفة لا تسعّر.
            'price_list' => $listId ? PriceList::query()->whereKey($listId)->where('is_active', true)->first() : null,
        ];
    }
}
