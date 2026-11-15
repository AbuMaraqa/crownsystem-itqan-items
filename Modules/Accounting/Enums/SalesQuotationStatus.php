<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SalesQuotationStatus: int implements HasLabel, HasColor
{
    case DRAFT = 1;
    case APPROVED = 2;
    case CONVERTED = 3;
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
            self::DRAFT => __('Draft'),
            self::APPROVED => __('Approved'),
            self::CONVERTED => __('Converted'),
            self::CANCELLED => __('Cancelled'),
        };
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::APPROVED => 'success',
            self::CONVERTED => 'info',
            self::CANCELLED => 'danger',
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
