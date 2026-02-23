<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalNotification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Endpoint notifikasi internal untuk user yang sedang login.
 *
 * Routes (tambahkan di routes/api.php):
 *   GET    /api/notifications              → index (list dengan filter)
 *   GET    /api/notifications/unread-count → unreadCount
 *   PATCH  /api/notifications/{id}/read   → markRead
 *   PATCH  /api/notifications/read-all    → markAllRead
 *   DELETE /api/notifications/{id}        → destroy
 */
class InternalNotificationController extends Controller
{
    /**
     * GET /api/notifications
     * List notifikasi milik user yang login.
     */
    public function index(Request $request)
    {
        $userId = Auth::id();

        $query = InternalNotification::forUser($userId)
            ->orderByDesc('created_at');

        // Filter hanya yang belum dibaca
        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        // Filter berdasarkan type
        if ($request->filled('type')) {
            $query->byType($request->type);
        }

        $perPage       = $request->integer('per_page', 20);
        $notifications = $query->paginate($perPage);

        return response()->json([
            'success'      => true,
            'data'         => $notifications,
            'unread_count' => NotificationService::unreadCount($userId),
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     * Jumlah notifikasi belum dibaca — untuk badge di navbar.
     */
    public function unreadCount()
    {
        return response()->json([
            'success'      => true,
            'unread_count' => NotificationService::unreadCount(Auth::id()),
        ]);
    }

    /**
     * PATCH /api/notifications/{id}/read
     * Tandai satu notifikasi sebagai sudah dibaca.
     */
    public function markRead($id)
    {
        $notification = InternalNotification::forUser(Auth::id())->find($id);

        if (!$notification) {
            return response()->json(['success' => false, 'message' => 'Notifikasi tidak ditemukan'], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success'      => true,
            'message'      => 'Notifikasi ditandai sudah dibaca',
            'unread_count' => NotificationService::unreadCount(Auth::id()),
        ]);
    }

    /**
     * PATCH /api/notifications/read-all
     * Tandai semua notifikasi user sebagai sudah dibaca.
     */
    public function markAllRead()
    {
        $count = NotificationService::markAllRead(Auth::id());

        return response()->json([
            'success'      => true,
            'message'      => "{$count} notifikasi ditandai sudah dibaca",
            'unread_count' => 0,
        ]);
    }

    /**
     * DELETE /api/notifications/{id}
     * Hapus satu notifikasi.
     */
    public function destroy($id)
    {
        $notification = InternalNotification::forUser(Auth::id())->find($id);

        if (!$notification) {
            return response()->json(['success' => false, 'message' => 'Notifikasi tidak ditemukan'], 404);
        }

        $notification->delete();

        return response()->json([
            'success'      => true,
            'message'      => 'Notifikasi dihapus',
            'unread_count' => NotificationService::unreadCount(Auth::id()),
        ]);
    }
}