<?php

namespace Modules\Core\Support;

/**
 * البث الحي (Reverb) تحديث للشاشات المفتوحة، لا جزء من العملية: اذا توقف خادم البث يُسجَّل
 * الخطأ (report) وتكمل العملية التي أطلقته، فلا يفشل حفظ صنف او طلبية بسببه.
 * كل حدث يُبث فوراً (ShouldBroadcastNow) يُرسل من هنا.
 */
final class LiveUpdate
{
    public static function send(object $event): void
    {
        rescue(fn () => event($event));
    }
}
