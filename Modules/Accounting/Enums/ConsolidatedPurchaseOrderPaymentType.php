<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum ConsolidatedPurchaseOrderPaymentType: int implements HasLabel
{
    case ADVANCE = 1;
    case PARTIAL = 2;
    case FINAL = 3;
    case OTHER = 4;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::ADVANCE => __('Advance Payment'),
            self::PARTIAL => __('Partial Payment'),
            self::FINAL => __('Final Payment'),
            self::OTHER => __('Other'),
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
