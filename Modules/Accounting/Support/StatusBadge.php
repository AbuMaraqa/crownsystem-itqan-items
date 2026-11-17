<?php

namespace Modules\Accounting\Support;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

/**
 * شارة الحالة في لوحة Vue: النص واللون من التعداد نفسه (getLabel/getColor)، فلا تُكتب
 * خريطة الوان في الصفحات. اللون يُحوَّل الى نغمات مكوّن Badge.vue.
 */
final class StatusBadge
{
    private const TONES = [
        'gray' => 'neutral',
        'primary' => 'brand',
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
        'info' => 'info',
    ];

    /**
     * @return array{label: string, tone: string}|null
     */
    public static function of(?HasLabel $status): ?array
    {
        if ($status === null) {
            return null;
        }

        return [
            'label' => (string) $status->getLabel(),
            'tone' => self::tone($status instanceof HasColor ? $status->getColor() : null),
        ];
    }

    public static function tone(string|array|null $color): string
    {
        return is_string($color) ? (self::TONES[$color] ?? 'neutral') : 'neutral';
    }
}
