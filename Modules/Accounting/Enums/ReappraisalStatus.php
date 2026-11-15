<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReappraisalStatus: int implements HasLabel
{
    case PENDING = 1;
    case ACCEPTED = 2;
    case APPEALED = 3;
    case SETTLED = 4;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => __('Pending'),
            self::ACCEPTED => __('Accepted'),
            self::APPEALED => __('Appealed'),
            self::SETTLED => __('Settled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::ACCEPTED => 'success',
            self::APPEALED => 'danger',
            self::SETTLED => 'gray',
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
