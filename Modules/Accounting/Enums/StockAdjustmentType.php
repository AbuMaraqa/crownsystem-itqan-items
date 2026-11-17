<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

/**
 * - رصيد افتتاحي: ادخال كميات المخزن عند بدء العمل على النظام (يقابله حساب الارصدة الافتتاحية).
 * - جرد: يُدخل العدد الفعلي، ويُسجَّل الفرق عن رصيد النظام عجزاً او زيادة.
 * - تسوية: زيادة او نقص مباشر بكمية موجبة او سالبة (تلف، كسر، عينات...).
 */
enum StockAdjustmentType: string implements HasLabel, HasColor
{
    case OPENING = 'opening';
    case COUNT = 'count';
    case ADJUSTMENT = 'adjustment';

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::OPENING => __('Opening Balance'),
            self::COUNT => __('Stock Count'),
            self::ADJUSTMENT => __('Stock Adjustment'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::OPENING => 'info',
            self::COUNT => 'warning',
            self::ADJUSTMENT => 'gray',
        };
    }
}
