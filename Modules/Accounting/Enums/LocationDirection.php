<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * اتجاه السهم على بطاقة الرف: البطاقة تُلصق على العمود والصنف على يمينه او يساره.
 */
enum LocationDirection: string implements HasLabel
{
    case RIGHT = 'right';
    case LEFT = 'left';
    case UP = 'up';
    case DOWN = 'down';

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::RIGHT => __('Right'),
            self::LEFT => __('Left'),
            self::UP => __('Up'),
            self::DOWN => __('Down'),
        };
    }

    public function arrow(): string
    {
        return match ($this) {
            self::RIGHT => '→',
            self::LEFT => '←',
            self::UP => '↑',
            self::DOWN => '↓',
        };
    }
}
