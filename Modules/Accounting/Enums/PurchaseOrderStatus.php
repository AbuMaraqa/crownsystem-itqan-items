<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

enum PurchaseOrderStatus: int implements HasLabel, HasColor
{
    case DRAFT = 1;
    case CONFIRMED = 2;
    case RECEIVED = 3;
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
            self::RECEIVED => __('Received'),
            self::INVOICED => __('Invoiced'),
            self::CANCELLED => __('Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::CONFIRMED => 'info',
            self::RECEIVED => 'warning',
            self::INVOICED => 'success',
            self::CANCELLED => 'danger',
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
