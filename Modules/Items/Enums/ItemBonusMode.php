<?php

namespace Modules\Items\Enums;

use Modules\Core\Contracts\HasLabel;

/**
 * الكمية المجانية في سطر الصنف: في البيع او الشراء او كليهما.
 */
enum ItemBonusMode: string implements HasLabel
{
    case NONE = 'none';
    case SELL = 'sell';
    case BUY = 'buy';
    case BOTH = 'both';

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::NONE => __('None'),
            self::SELL => __('In Sales'),
            self::BUY => __('In Purchases'),
            self::BOTH => __('In Sales and Purchases'),
        };
    }
}
