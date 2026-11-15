<?php

namespace Modules\Accounting\Support;

/**
 * نصيب مستند من كغم سطر الصنف الرئيسي وقيمته حين يتوزع السطر على عدة سندات:
 * بنسبة ما عالجه السند من قطع السطر، محسوبة تراكمياً حتى تبلغ السندات مجموع السطر تماماً
 * بلا فرق تقريب، بأي ترتيب رُحّلت.
 */
class MainItemShare
{
    private const EPSILON = 0.00005;

    /**
     * @param  float  $total  مجموع السطر (كغم او قيمة)
     * @param  float  $whole  مقياس القطع كلها في السطر (وزن محسوب، او عدد ان لم يكن لها وزن)
     * @param  float  $prior  ما عولج من المقياس في سندات اخرى
     * @param  float  $current  ما يعالجه هذا السند
     */
    public static function slice(float $total, float $whole, float $prior, float $current): float
    {
        if ($whole <= 0 || $current <= 0) {
            return 0.0;
        }

        return round(self::upTo($total, $whole, $prior + $current) - self::upTo($total, $whole, $prior), 4);
    }

    private static function upTo(float $total, float $whole, float $measure): float
    {
        return $measure >= $whole - self::EPSILON ? round($total, 4) : round($total * $measure / $whole, 4);
    }
}
