<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasLabel;

enum ChequeDirection: int implements HasLabel
{
    case INCOMING = 1;
    case OUTGOING = 2;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::INCOMING => __('Incoming'),
            self::OUTGOING => __('Outgoing'),
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
