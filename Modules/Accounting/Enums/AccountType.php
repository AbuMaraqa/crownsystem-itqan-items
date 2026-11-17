<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

enum AccountType: int implements HasLabel, HasColor
{
    case ASSET = 1;
    case LIABILITY = 2;
    case EQUITY = 3;
    case REVENUE = 4;
    case EXPENSE = 5;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::ASSET => __('Asset'),
            self::LIABILITY => __('Liability'),
            self::EQUITY => __('Equity'),
            self::REVENUE => __('Revenue'),
            self::EXPENSE => __('Expense'),
        };
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::ASSET => 'success',
            self::LIABILITY => 'danger',
            self::EQUITY => 'warning',
            self::REVENUE => 'info',
            self::EXPENSE => 'gray',
        };
    }

    public function value(): int
    {
        return match ($this) {
            self::ASSET => 1,
            self::LIABILITY => 2,
            self::EQUITY => 3,
            self::REVENUE => 4,
            self::EXPENSE => 5,
        };
    }
}
