<?php

namespace Modules\Items\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use DomainException;

/**
 * قراءة رقم عشري بدقة بلا فاصلة عائمة، لحساب الاسعار والوحدات. المدخل غير
 * الرقمي خطأ لا صفر: السعر لا يُحسب صامتاً على قيمة خاطئة.
 */
final class ExactDecimal
{
    /** دقة الاسعار والكميات المحسوبة (decimal(18,4)). */
    public const MONEY_SCALE = 4;

    /** دقة معامل الوحدة ومعامل المعادلة وكمية العبوة (decimal(18,6)). */
    public const FACTOR_SCALE = 6;

    public static function of(mixed $value, string $label = 'value'): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new DomainException("Invalid {$label}.");
        }

        try {
            return BigDecimal::of(is_string($value) ? trim($value) : (string) $value);
        } catch (MathException) {
            throw new DomainException("Invalid {$label}: {$value}");
        }
    }
}
