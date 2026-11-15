<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum ShippingTerms: int implements HasLabel
{
    case EXW = 1;
    case FOB = 2;
    case CIF = 3;
    case CFR = 4;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::EXW => __('Ex Works (EXW)'),
            self::FOB => __('Free on Board (FOB)'),
            self::CIF => __('Cost, Insurance & Freight (CIF)'),
            self::CFR => __('Cost & Freight (C&F)'),
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
