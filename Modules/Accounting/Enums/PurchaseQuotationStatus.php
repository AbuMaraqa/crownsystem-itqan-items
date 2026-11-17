<?php

namespace Modules\Accounting\Enums;

use Modules\Core\Contracts\HasColor;
use Modules\Core\Contracts\HasLabel;

/**
 * دورة عرض سعر الشراء: مسودة تُسعَّر، ثم معتمد (اسعاره ملزمة)، ثم مفوتر حين تُنشأ
 * منه فاتورة الشراء. الملغى يحرر الطلبية لعرض جديد.
 */
enum PurchaseQuotationStatus: int implements HasLabel, HasColor
{
    case DRAFT = 1;
    case APPROVED = 2;
    case INVOICED = 3;
    case CANCELLED = 4;

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => __('Draft'),
            self::APPROVED => __('Approved'),
            self::INVOICED => __('Invoiced'),
            self::CANCELLED => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::APPROVED => 'success',
            self::INVOICED => 'info',
            self::CANCELLED => 'danger',
        };
    }
}
