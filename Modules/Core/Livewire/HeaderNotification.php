<?php

namespace Modules\Core\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Services\NotificationService;

/**
 * جرس الاشعارات في الشريط العلوي: عدد غير المقروء، آخر الاشعارات، وتحديدها كمقروءة/غير مقروءة.
 * يتحدث فوراً عند وصول اشعار حي (notification-received) ويتحقق كل دقيقة احتياطاً اذا كان البث متوقفاً.
 */
class HeaderNotification extends Component
{
    public int $limit = 10;

    #[On('notification-received')]
    #[On('refresh-notification-bell')]
    public function refreshNotifications(mixed ...$payload): void
    {
        // اعادة الرسم تكفي لجلب الاشعارات الجديدة؛ $payload يستقبل بيانات الحدث (مثل id) دون استخدامها.
    }

    /**
     * يفتح الاشعار: يحدده كمقروء ثم ينتقل لرابطه (او لصفحة الاشعارات اذا لم يكن له رابط).
     */
    public function open(string $id, NotificationService $notifications)
    {
        $notifications->markAsRead(Auth::user(), $id);

        $url = $notifications->query(Auth::user())->find($id)?->data['url'] ?? null;

        if (! $url && Auth::user()->can('Notification View List')) {
            $url = route('notifications.index');
        }

        return $url ? $this->redirect($url) : null;
    }

    public function toggleRead(string $id, NotificationService $notifications): void
    {
        $notifications->toggleRead(Auth::user(), $id);
    }

    public function markAllAsRead(NotificationService $notifications): void
    {
        $notifications->markAllAsRead(Auth::user());
    }

    public function remove(string $id, NotificationService $notifications): void
    {
        $notifications->delete(Auth::user(), $id);
    }

    public function render(NotificationService $notifications)
    {
        $user = Auth::user();

        return view('core::livewire.header-notification', [
            'notifications' => $user
                ? $notifications->query($user)->latest()->take($this->limit)->get()->map(fn ($n) => $notifications->present($n))
                : collect(),
            'unreadCount' => $user ? $notifications->unreadCount($user) : 0,
        ]);
    }
}
