<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

/**
 * - توريد للمخزن: البضاعة تُستلم بسند استلام فتدخل المخزون.
 * - شحن مباشر للزبون: المورد (مثلاً في الصين) يشحن للزبون مباشرة فلا تدخل المخزن؛
 *   تُحمَّل كلفتها على تكلفة البضاعة المباعة عند ترحيل فاتورة الشراء.
 */
enum PurchaseOrderDeliveryType: string implements HasLabel, HasColor
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
            self::WAREHOUSE => __('Delivery to warehouse'),
            self::DIRECT => __('Direct shipment to customer'),
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
