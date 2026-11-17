<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

enum SalesInvoiceStatus: int implements HasColor, HasLabel
{
    case DRAFT = 1;
    case POSTED = 2;
    case CANCELLED = 3;

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
            self::POSTED => __('Posted'),
            self::CANCELLED => __('Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::POSTED => 'success',
            self::CANCELLED => 'danger',
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
