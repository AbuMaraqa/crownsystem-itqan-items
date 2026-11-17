<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

enum PeriodClosingVoucherStatus: int implements HasColor, HasLabel
{
    case POSTED = 1;
    case CANCELLED = 2;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::POSTED => __('Posted'),
            self::CANCELLED => __('Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::POSTED => 'success',
            self::CANCELLED => 'danger',
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
