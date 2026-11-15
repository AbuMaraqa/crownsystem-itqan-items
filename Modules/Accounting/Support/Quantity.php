<?php

namespace Modules\Accounting\Support;

/**
 * عرض الكميات بلا اصفار زائدة بعد الفاصلة: 52.0000 ← 52، و 1.50 ← 1.5، و 20 تبقى 20.
 */
class Quantity
{
    public static function format(float|int|string|null $value, int $decimals = 2): string
    {
        $formatted = number_format((float) $value, $decimals, '.', ',');

        return $decimals > 0 ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
