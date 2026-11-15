<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum ShippingClassification: int implements HasLabel
{
    case PARTIAL = 1;
    case CONTAINER = 2;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PARTIAL => __('Partial'),
            self::CONTAINER => __('Container'),
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
