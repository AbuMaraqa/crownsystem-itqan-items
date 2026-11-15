<?php

namespace Modules\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Modules\Accounting\Support\StockLimits;
use Modules\Accounting\Support\StockStatus;
use Modules\Items\Entities\Product;

/**
 * حالة الصنف في قسم المتابعة. الصنف «الطبيعي» (فوق الحد الادنى 1) او غير المراقَب
 * لا حالة له (null) ولا يظهر في القسم.
 *
 * - تنبيه: وصل للحد الادنى 1 (يكفي نحو 3 اشهر).
 * - يجب الطلب: وصل للحد الادنى 2 (يكفي نحو شهر).
 * - نفد: الرصيد صفر.
 */
enum StockAlertLevel: string implements HasColor, HasLabel
{
    case WARNING = 'warning';
    case REORDER = 'reorder';
    case OUT = 'out';

    /**
     * الحدود نفسها تُقرأ من StockStatus، فلا تتكرر قاعدة المقارنة.
     */
    public static function for(Product $product, float $quantity): ?self
    {
        if (! StockLimits::isMonitored($product)) {
            return null;
        }

        return match (StockStatus::for($product, $quantity)) {
            StockStatus::OUT => self::OUT,
            StockStatus::CRITICAL => self::REORDER,
            StockStatus::LOW => self::WARNING,
            default => null,
        };
    }

    /**
     * الاخطر اولاً: نفد، ثم يجب الطلب، ثم تنبيه.
     */
    public function severity(): int
    {
        return match ($this) {
            self::WARNING => 1,
            self::REORDER => 2,
            self::OUT => 3,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::WARNING => __('Warning'),
            self::REORDER => __('Must Order'),
            self::OUT => __('Out of Stock'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::WARNING => __('Reached Min Limit 1'),
            self::REORDER => __('Reached Min Limit 2'),
            self::OUT => __('Quantity is zero'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::WARNING => 'warning',
            self::REORDER => 'danger',
            self::OUT => 'dark',
        };
    }

    /**
     * لون الاشعار (NotificationTone) لكل حالة.
     */
    public function notificationColor(): string
    {
        return $this === self::WARNING ? 'warning' : 'danger';
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
