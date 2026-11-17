<?php

namespace Modules\Items\Enums;

use Modules\Core\Contracts\HasLabel;

/**
 * مصدر تكلفة الخدمة: داخلية (عمال الشركة ومعداتها، تُحمَّل بالتكلفة المعيارية)،
 * او خارجية (جهة اخرى تقدمها، كشركة نقل، فتصبح ديناً لها).
 */
enum ServiceCostSource: string implements HasLabel
{
    case INTERNAL = 'internal';
    case EXTERNAL = 'external';

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::INTERNAL => __('Internal Cost'),
            self::EXTERNAL => __('External Provider'),
        };
    }
}
