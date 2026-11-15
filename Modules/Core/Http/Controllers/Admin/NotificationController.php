<?php

namespace Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Services\NotificationService;
use Modules\Core\Support\AdminTable;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * اشعارات المستخدم الحالي فقط: صفحة الاشعارات، وجرس الترويسة. كل عملية مقيدة
 * باشعاراته داخل NotificationService.
 */
class NotificationController extends Controller
{
    private const LATEST = 10;

    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Core/Notifications/Index', [
            'table' => AdminTable::for($this->notifications->query($request->user()))
                ->filters(
                    AllowedFilter::callback('search', fn (Builder $query, mixed $value) => $query->whereLike('data', '%'.addcslashes(trim((string) $value), '%_\\').'%')),
                    AllowedFilter::callback('read', fn (Builder $query, mixed $value) => filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        ? $query->whereNotNull('read_at')
                        : $query->whereNull('read_at')),
                )
                ->sorts('created_at')
                ->defaultSort('-created_at')
                ->rows(fn (DatabaseNotification $notification) => $this->row($notification)),
            'unread' => $this->notifications->unreadCount($request->user()),
        ]);
    }

    /**
     * الجرس: آخر الاشعارات بصيغة JSON.
     */
    public function latest(Request $request): JsonResponse
    {
        $rows = $this->notifications->query($request->user())
            ->latest()
            ->limit(self::LATEST)
            ->get()
            ->map(fn (DatabaseNotification $notification) => $this->row($notification));

        return response()->json(['data' => $rows]);
    }

    /**
     * يعلّمه مقروءاً ثم يفتح رابطه الآمن، او يعود للصفحة.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $record = $this->notifications->query($request->user())->findOrFail($notification);
        $this->notifications->markAsRead($request->user(), $record->id);

        $url = $this->notifications->safeUrl($record->data['url'] ?? null);

        return $url ? redirect($url) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->notifications->markAllAsRead($request->user());

        return back()->with('success', __('All notifications marked as read'));
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $this->notifications->markAsRead($request->user(), $notification);

        return back();
    }

    public function toggle(Request $request, string $notification): RedirectResponse
    {
        $this->notifications->toggleRead($request->user(), $notification);

        return back();
    }

    /**
     * تحديد عدة اشعارات: مقروءة، غير مقروءة، او حذف.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:read,unread,delete'],
            'ids' => ['required', 'array'],
            'ids.*' => ['uuid'],
        ]);

        match ($data['action']) {
            'read' => $this->notifications->markAsRead($request->user(), $data['ids']),
            'unread' => $this->notifications->markAsUnread($request->user(), $data['ids']),
            'delete' => $this->notifications->delete($request->user(), $data['ids']),
        };

        return back();
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $this->notifications->delete($request->user(), $notification);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(DatabaseNotification $notification): array
    {
        $item = $this->notifications->present($notification);

        return [
            'id' => $item['id'],
            'title' => $item['title'] ?: null,
            'message' => $item['message'],
            'url' => $this->notifications->safeUrl($item['url']),
            'time' => $notification->created_at?->diffForHumans(),
            'date' => $notification->created_at?->format('Y-m-d H:i'),
            'read' => $item['is_read'],
            'read_at' => $notification->read_at?->format('Y-m-d H:i'),
            'resolved' => filled($item['resolved_at']),
            'sender' => $item['sender_name'] ?: __('System'),
        ];
    }
}
