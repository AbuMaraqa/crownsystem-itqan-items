<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

enum ChequeStatus: int implements HasColor, HasLabel
{
    case UNDER_COLLECTION = 1;
    case CLEARED = 2;
    case BOUNCED = 3;
    case CANCELLED = 4;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::UNDER_COLLECTION => __('Under Collection'),
            self::CLEARED => __('Cleared'),
            self::BOUNCED => __('Bounced'),
            self::CANCELLED => __('Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::UNDER_COLLECTION => 'warning',
            self::CLEARED => 'success',
            self::BOUNCED => 'danger',
            self::CANCELLED => 'gray',
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
