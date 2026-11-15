<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum NormalBalance: int implements HasLabel, HasColor
{
    case DEBIT = 1;
    case CREDIT = 2;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::DEBIT => __('Debit'),
            self::CREDIT => __('Credit'),
        };
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::DEBIT => 'success',
            self::CREDIT => 'warning',
        };
    }

    public function value(): int
    {
        return match ($this) {
            self::DEBIT => 1,
            self::CREDIT => 2,
        };
    }
}
