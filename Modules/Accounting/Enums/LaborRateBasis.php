<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * اساس احتساب أجر العامل في أمر الإنتاج. الحساب واحد (الكمية × الأجر)، ويحدد
 * الاساس معنى الكمية: ساعات، او ايام، او قطع منجزة، او مبلغ مقطوع (الكمية 1).
 */
enum LaborRateBasis: string implements HasLabel
{
    case HOUR = 'hour';
    case DAY = 'day';
    case PIECE = 'piece';
    case FIXED = 'fixed';

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::HOUR => __('Per Hour'),
            self::DAY => __('Per Day'),
            self::PIECE => __('Per Piece'),
            self::FIXED => __('Lump Sum'),
        };
    }

    public function unitLabel(): string
    {
        return match ($this) {
            self::HOUR => __('Hours'),
            self::DAY => __('Days'),
            self::PIECE => __('Pieces'),
            self::FIXED => __('Times'),
        };
    }
}
