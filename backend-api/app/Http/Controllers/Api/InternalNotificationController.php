<?php
namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InternalNotificationController extends Controller
{
    public function __construct(protected NotificationService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = (int) ($request->limit ?? 20);

        $notifications = $this->service->getUnreadForUser($user->user_id, $limit);

        return response()->json([
            'success' => true,
            'data' => $notifications,
        ]);
    }

    public function markAsRead(int $notificationId, Request $request): JsonResponse
    {
        $user = $request->user();

        $this->service->markAsRead($notificationId, $user->user_id);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $this->service->markAllAsRead($user->user_id);

        return response()->json([
            'success' => true,
            'message' => "Marked {$count} notifications as read",
        ]);
    }
}
