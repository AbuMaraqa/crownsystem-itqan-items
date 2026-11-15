<?php

namespace Modules\Core\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\NotificationService;
use Modules\Core\Traits\ApiResponse;

/**
 * اشعارات المستخدم الحالي للتطبيق: القائمة، عدد غير المقروء، وتحديد المقروء/غير المقروء والحذف.
 * كل العمليات مقيدة باشعارات المستخدم نفسه عبر NotificationService.
 */
class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(protected NotificationService $notifications) {}

    /**
     * @OA\Get(
     * path="/api/v1/notifications",
     * summary="Get the current user's notifications",
     * tags={"Notifications"},
     * security={{"sanctum": {}}},
     * @OA\Parameter(name="status", in="query", required=false, description="read | unread", @OA\Schema(type="string")),
     * @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer")),
     * @OA\Response(response=200, description="Notifications retrieved successfully")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', config('core.pagination.per_page', 15)), config('core.pagination.max_per_page', 100));
        $read = match ($request->input('status')) {
            'read' => true,
            'unread' => false,
            default => null,
        };

        $page = $this->notifications->query($request->user(), $read)
            ->latest()
            ->paginate(max($perPage, 1))
            ->appends($request->query());

        return response()->json([
            'status' => true,
            'message' => __('Notifications retrieved successfully'),
            'data' => collect($page->items())->map(fn ($n) => $this->transform($this->notifications->present($n))),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread_count' => $this->notifications->unreadCount($request->user()),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @OA\Get(path="/api/v1/notifications/unread-count", summary="Unread notifications count", tags={"Notifications"}, security={{"sanctum": {}}},
     * @OA\Response(response=200, description="OK"))
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->successResponse(['unread_count' => $this->notifications->unreadCount($request->user())]);
    }

    /**
     * @OA\Post(path="/api/v1/notifications/{id}/read", summary="Mark a notification as read", tags={"Notifications"}, security={{"sanctum": {}}},
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     * @OA\Response(response=200, description="OK"))
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $this->findOrFail($request, $id);
        $this->notifications->markAsRead($request->user(), $id);

        return $this->stateResponse($request, __('Notification marked as read'));
    }

    /**
     * @OA\Post(path="/api/v1/notifications/{id}/unread", summary="Mark a notification as unread", tags={"Notifications"}, security={{"sanctum": {}}},
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     * @OA\Response(response=200, description="OK"))
     */
    public function markAsUnread(Request $request, string $id): JsonResponse
    {
        $this->findOrFail($request, $id);
        $this->notifications->markAsUnread($request->user(), $id);

        return $this->stateResponse($request, __('Notification marked as unread'));
    }

    /**
     * @OA\Post(path="/api/v1/notifications/read-all", summary="Mark all notifications as read", tags={"Notifications"}, security={{"sanctum": {}}},
     * @OA\Response(response=200, description="OK"))
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->notifications->markAllAsRead($request->user());

        return $this->stateResponse($request, __('All notifications marked as read'));
    }

    /**
     * @OA\Delete(path="/api/v1/notifications/{id}", summary="Delete a notification", tags={"Notifications"}, security={{"sanctum": {}}},
     * @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
     * @OA\Response(response=200, description="OK"))
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->findOrFail($request, $id);
        $this->notifications->delete($request->user(), $id);

        return $this->stateResponse($request, __('Notification deleted'));
    }

    protected function findOrFail(Request $request, string $id): void
    {
        // اشعار مستخدم آخر يعامل كغير موجود (404) حتى لا يكشف وجوده.
        $this->notifications->query($request->user())->findOrFail($id);
    }

    protected function stateResponse(Request $request, string $message): JsonResponse
    {
        return $this->successResponse(['unread_count' => $this->notifications->unreadCount($request->user())], $message);
    }

    protected function transform(array $item): array
    {
        // tone و icon_svg للعرض في الويب فقط.
        unset($item['tone'], $item['icon_svg']);

        return [
            ...$item,
            'read_at' => $item['read_at']?->toIso8601String(),
            'created_at' => $item['created_at']?->toIso8601String(),
        ];
    }
}
