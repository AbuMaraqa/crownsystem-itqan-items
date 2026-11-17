<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

enum AccountingPeriodStatus: int implements HasLabel, HasColor
{
    case OPEN = 1;
    case CLOSED = 2;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::OPEN => __('Open'),
            self::CLOSED => __('Closed'),
        };
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::OPEN => 'success',
            self::CLOSED => 'danger',
        };
    }

    public function value(): int
    {
        return match ($this) {
            self::OPEN => 1,
            self::CLOSED => 2,
        };
    }
}
