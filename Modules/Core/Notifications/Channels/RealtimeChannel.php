<?php

namespace Modules\Core\Notifications\Channels;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * يبث الإشعار عبر Reverb فوراً دون المرور بطابور المهام.
 *
 * - ينتظر اعتماد المعاملة الجارية حتى يجد المتصفح الإشعار محفوظاً عند تحديث الجرس.
 * - تعطل خادم البث لا يُفشل العملية الأصلية؛ يُسجَّل الخطأ فقط.
 */
class RealtimeChannel extends BroadcastChannel
{
    public function send($notifiable, Notification $notification)
    {
        $message = $this->getData($notifiable, $notification);

        $event = new BroadcastNotificationCreated(
            $notifiable, $notification, is_array($message) ? $message : $message->data
        );

        DB::afterCommit(function () use ($event) {
            rescue(fn () => (new BroadcastEvent($event))->handle(app(BroadcastingFactory::class)));
        });

        return null;
    }
}
