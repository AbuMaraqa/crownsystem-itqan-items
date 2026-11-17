<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

/**
 * مراحل الطلبية التجميعية مع المورد. القيم تطابق ما كان مخزناً من
 * PurchaseOrderStatus (مسودة، مؤكدة، مستلمة، مفوترة، ملغاة) فلا تحتاج
 * الطلبيات القائمة الى ترحيل بيانات.
 */
enum ConsolidatedPurchaseOrderStatus: int implements HasLabel, HasColor
{
    case UNCONFIRMED = 1;
    case CONFIRMED = 2;
    case CONTAINER_LOADED = 3;
    case READY_FOR_INVOICING = 4;
    case CANCELLED = 5;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value() => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::UNCONFIRMED => __('Unconfirmed Purchase With Supplier'),
            self::CONFIRMED => __('Purchase Confirmed'),
            self::CONTAINER_LOADED => __('Container Loaded'),
            self::READY_FOR_INVOICING => __('Ready for Invoicing'),
            self::CANCELLED => __('Deleted / Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::UNCONFIRMED => 'gray',
            self::CONFIRMED => 'info',
            self::CONTAINER_LOADED => 'warning',
            self::READY_FOR_INVOICING => 'success',
            self::CANCELLED => 'danger',
        };
    }

    public function value(): int
    {
        return $this->value;
    }
}
