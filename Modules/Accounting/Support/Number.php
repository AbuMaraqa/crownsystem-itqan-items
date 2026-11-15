<?php

namespace Modules\Accounting\Support;

/**
 * تنسيق الارقام في شاشات الطلبيات: بلا اصفار زائدة وبلا فواصل.
 *
 * الجداول كانت تطبع number_format(x, 3) او (x, 4) دائماً، فالكمية 15 تظهر
 * "15.000" والسعر 30 يظهر "30.0000". الاصفار الزائدة لا تضيف معلومة،
 * وتخلط القراءة السريعة على الشاشة، وتوهم ان الرقم فيه كسور.
 *
 * الدقة هنا سقف لا حشو: 29.999 تبقى كما هي، و15 تظهر 15.
 */
class Number
{
    /** دقة الكميات والاوزان — نفس خطوة حقول الادخال (0.001). */
    public const QUANTITY_PRECISION = 3;

    /** دقة الاسعار والمبالغ كما تُخزَّن في القاعدة. */
    public const MONEY_PRECISION = 4;

    public static function clean(mixed $value, int $precision = self::MONEY_PRECISION): string
    {
        $rounded = round((float) $value, $precision);

        // round() قد تُرجع -0.0 لقيمة سالبة صغيرة، و"-0" ليست رقماً يُعرض.
        if ($rounded == 0.0) {
            return '0';
        }

        $text = number_format($rounded, $precision, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    public static function quantity(mixed $value): string
    {
        return self::clean($value, self::QUANTITY_PRECISION);
    }

    public static function money(mixed $value): string
    {
        return self::clean($value, self::MONEY_PRECISION);
    }

    /**
     * الكمية بعد قص ضجيج الفاصلة العشرية.
     *
     * الحساب كان يقرّب على اربع خانات، وحقول الادخال كلها بخطوة 0.001،
     * فأي فرق بالخانة الرابعة (30 - 0.0001 مثلاً) يظهر للمستخدم كـ29.9999
     * ولا يستطيع كتابته أصلاً، ويبقي بند طلبية الشراء "غير مستهلك بالكامل"
     * بسبب متبقٍّ لا يساوي شيئاً.
     */
    public static function normalizeQuantity(mixed $value): float
    {
        return round((float) $value, self::QUANTITY_PRECISION);
    }
}
