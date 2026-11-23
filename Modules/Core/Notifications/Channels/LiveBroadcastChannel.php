<?php

namespace Modules\Core\Notifications\Channels;

use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Notification;

/**
 * قناة البث الحي للاشعارات: الاشعار محفوظ في قاعدة البيانات قبلها، فتوقّف خادم البث يُسجَّل
 * ولا يُفشل الارسال (يراه المستخدم عند تحديث الجرس). القاعدة نفسها في LiveUpdate.
 */
class LiveBroadcastChannel extends BroadcastChannel
{
    /**
     * @return array<int, mixed>|null
     */
    public function send($notifiable, Notification $notification)
    {
        return rescue(fn () => parent::send($notifiable, $notification));
    }
}
