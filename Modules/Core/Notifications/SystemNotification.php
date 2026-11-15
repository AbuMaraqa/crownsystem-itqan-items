<?php

namespace Modules\Core\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Modules\Core\Notifications\Channels\RealtimeChannel;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

/**
 * اشعار النظام الموحد: يحفظ في جدول notifications (مقروء/غير مقروء عبر read_at)،
 * ويبث مباشرة على قناة المستخدم الخاصة، ويرسل للجوال عبر FCM اذا كان للمستخدم جهاز مسجل.
 *
 * لا ترسله مباشرة؛ استخدم NotificationService::send حتى تمر البيانات بنفس الشكل دائماً.
 */
class SystemNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $payload  البيانات المحفوظة كما يبنيها NotificationService
     */
    public function __construct(public array $payload) {}

    public function via(object $notifiable): array
    {
        // RealtimeChannel يبث فوراً بعد اعتماد المعاملة، وتعطل Reverb لا يفشل الارسال.
        $channels = ['database', RealtimeChannel::class];

        if (method_exists($notifiable, 'deviceTokens') && $notifiable->deviceTokens()->exists()) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload + ['sent_at' => now()->toIso8601String()]);
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage(notification: new FcmNotification(
            title: $this->payload['title'] ?? '',
            body: $this->payload['message'] ?? '',
        )))->data(array_map('strval', array_filter([
            'notification_id' => $this->id,
            'event' => $this->payload['event'] ?? null,
            'subject_type' => $this->payload['subject_type'] ?? null,
            'subject_id' => $this->payload['subject_id'] ?? null,
            'url' => $this->payload['url'] ?? null,
        ], fn ($value) => $value !== null)));
    }
}
