<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

enum JournalEntryStatus: int implements HasLabel, HasColor
{
    case DRAFT = 1;
    case POSTED = 2;
    case VOIDED = 3;

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
            self::VOIDED => __('Voided'),
        };
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::POSTED => 'success',
            self::VOIDED => 'danger',
        };
    }

    public function value(): int
    {
        return match ($this) {
            self::DRAFT => 1,
            self::POSTED => 2,
            self::VOIDED => 3,
        };
    }
}
