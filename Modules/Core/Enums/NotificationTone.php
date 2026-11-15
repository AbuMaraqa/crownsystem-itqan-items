<?php

namespace Modules\Core\Enums;

/**
 * لون الاشعار (يحفظ نصاً في حقل color)، ومنه تؤخذ تنسيقات العرض في الجرس وصفحة الاشعارات.
 * القيم نفسها يستخدمها مستمع الاشعارات الحية لتحديد مستوى التنبيه والصوت.
 */
enum NotificationTone: string
{
    case PRIMARY = 'primary';
    case INFO = 'info';
    case SUCCESS = 'success';
    case WARNING = 'warning';
    case DANGER = 'danger';

    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::PRIMARY;
    }

    /**
     * خلفية وايقونة دائرة الاشعار.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::PRIMARY => 'bg-primary/10 text-primary',
            self::INFO => 'bg-info/10 text-info',
            self::SUCCESS => 'bg-success/10 text-success',
            self::WARNING => 'bg-warning/10 text-warning',
            self::DANGER => 'bg-danger/10 text-danger',
        };
    }
}
