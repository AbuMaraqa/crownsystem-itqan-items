<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

/**
 * - من المخزن: تُسلَّم الطلبية من مخزوننا بسند تسليم.
 * - توريد مباشر من المورد: يوردها مورد آخر للزبون مباشرة؛ عند تأكيدها يُشعَر قسم المشتريات
 *   لينشئ طلبية شراء «شحن مباشر للزبون» مرتبطة بها.
 */
enum SalesOrderFulfillmentType: string implements HasLabel, HasColor
{
    case WAREHOUSE = 'warehouse';
    case DIRECT = 'direct';

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::WAREHOUSE => __('From our warehouse'),
            self::DIRECT => __('Direct supply from a supplier'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::WAREHOUSE => 'info',
            self::DIRECT => 'warning',
        };
    }
}
