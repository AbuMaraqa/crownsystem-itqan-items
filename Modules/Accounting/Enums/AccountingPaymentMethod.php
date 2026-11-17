<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasLabel;

enum AccountingPaymentMethod: int implements HasLabel
{
    case CASH = 1;
    case CHEQUE = 2;
    case BANK_TRANSFER = 3;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::CASH => __('Cash'),
            self::CHEQUE => __('Cheque'),
            self::BANK_TRANSFER => __('Bank Transfer'),
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
