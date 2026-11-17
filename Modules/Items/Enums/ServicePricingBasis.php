<?php

namespace Modules\Items\Enums;

use Modules\Core\Contracts\HasLabel;

/**
 * اساس تسعير بند الخدمة. الحساب واحد في كل الحالات (الكمية × السعر)، ويحدد
 * الاساس معنى الكمية: عدد الوحدات، او الكيلوغرامات، او عدد النقلات (مبلغ مقطوع للنقلة).
 */
enum ServicePricingBasis: string implements HasLabel
{
    case QUANTITY = 'quantity';
    case WEIGHT = 'weight';
    case TRIP = 'trip';

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::QUANTITY => __('Per Quantity'),
            self::WEIGHT => __('Per Kg'),
            self::TRIP => __('Per Trip'),
        };
    }

    public function unitLabel(): string
    {
        return match ($this) {
            self::QUANTITY => __('Unit'),
            self::WEIGHT => __('kg'),
            self::TRIP => __('Trip'),
        };
    }
}
