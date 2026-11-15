<?php

namespace Modules\Core\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Entities\User;
use Modules\Core\Services\NotificationService;

/**
 * يعرض التنبيهات المنبثقة للإشعارات الحية (resources/js/notifications.js)،
 * ويتولى فتح الإشعار عند النقر على التنبيه: يعلّمه مقروءاً ثم ينتقل إلى رابطه.
 */
class GlobalNotificationListener extends Component
{
    #[On('notification-received')]
    public function syncUnreadCount(): void
    {
        $this->js('$store.notifications?.setUnread('.$this->unreadCount().')');
    }

    #[On('notification-open')]
    public function open(string $id, NotificationService $notifications): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $notification = $notifications->query($user)->find($id);

        if (! $notification) {
            return;
        }

        $notifications->markAsRead($user, $id);
        $this->dispatch('refresh-notification-bell');

        $url = $notifications->safeUrl($notification->data['url'] ?? null);

        if ($url !== null) {
            $this->redirect($url);

            return;
        }

        $this->syncUnreadCount();
    }

    public function render()
    {
        return view('core::livewire.global-notification-listener', [
            'unreadCount' => $this->unreadCount(),
        ]);
    }

    protected function unreadCount(): int
    {
        $user = auth()->user();

        return $user instanceof User ? app(NotificationService::class)->unreadCount($user) : 0;
    }
}
