<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasLabel;

enum ShippingType: int implements HasLabel
{
    case LAND = 1;
    case AIR = 2;
    case SEA = 3;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::LAND => __('Land'),
            self::AIR => __('Air'),
            self::SEA => __('Sea'),
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
