<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SalesOrderStatus: int implements HasLabel, HasColor
{
    case DRAFT = 1;
    case CONFIRMED = 2;
    case PREPARING = 3;
    case INVOICED = 4;
    case CANCELLED = 5;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => __('Draft'),
            self::CONFIRMED => __('Confirmed'),
            self::PREPARING => __('In Preparation'),
            self::INVOICED => __('Invoiced'),
            self::CANCELLED => __('Cancelled'),
        };
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::CONFIRMED => 'info',
            self::PREPARING => 'warning',
            self::INVOICED => 'success',
            self::CANCELLED => 'danger',
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
