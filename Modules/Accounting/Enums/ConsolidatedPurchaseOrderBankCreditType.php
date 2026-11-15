<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum ConsolidatedPurchaseOrderBankCreditType: int implements HasLabel
{
    case PAYMENT_CREDIT = 1;
    case LETTER_OF_GUARANTEE = 2;
    case LETTER_OF_CREDIT = 3;
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
            self::PAYMENT_CREDIT => __('Payment Credit'),
            self::LETTER_OF_GUARANTEE => __('Letter of Guarantee'),
            self::LETTER_OF_CREDIT => __('Letter of Credit'),
            self::OTHER => __('Other'),
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
