<?php

namespace Modules\Core\Notifications\Channels;

use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

/**
 * اشعار الجوال (Firebase) اضافة على الاشعار المخزّن، لا شرط له: لا يُحاول الا لمستخدم له جهاز
 * مسجل، ويُبنى Firebase عند الحاجة فقط، وفشله (اعدادات ناقصة او خدمة متوقفة) يُسجَّل ولا يُفشل
 * العملية. القاعدة نفسها في LiveBroadcastChannel وLiveUpdate.
 */
class PushChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (blank($notifiable->routeNotificationFor('fcm', $notification))) {
            return;
        }

        rescue(fn () => app(FcmChannel::class)->send($notifiable, $notification));
    }
}
