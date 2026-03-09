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
 * Routes (routes/api.php):
 *   GET    /api/notifications              → index
 *   GET    /api/notifications/unread-count → unreadCount
 *   PATCH  /api/notifications/{id}/read   → markRead
 *   PATCH  /api/notifications/read-all    → markAllRead
 *   DELETE /api/notifications/{id}        → destroy
 *
 * Catatan scope:
 *   - ?scope=global  → notifikasi Header (exclude PO types) — DEFAULT
 *   - ?scope=po      → notifikasi PONotificationBell saja
 *   - ?scope=all     → semua notifikasi tanpa filter type
 */
class InternalNotificationController extends Controller
{
    /**
     * GET /api/notifications
     *
     * ✅ FIX: tambah ?scope=global|po|all
     *   - global (default) → exclude PO-only types
     *   - po               → hanya PO types
     *   - all              → tanpa filter type
     */
    public function index(Request $request)
    {
        $userId = Auth::id();
        $scope  = $request->input('scope', 'global'); // default: global (header bell)

        $query = InternalNotification::forUser($userId)
            ->orderByDesc('created_at');

        // ✅ Filter berdasarkan scope
        match ($scope) {
            'po'     => $query->onlyPoTypes(),
            'all'    => null, // tidak ada filter type
            default  => $query->excludePoTypes(), // 'global' dan lainnya
        };

        // Filter hanya yang belum dibaca
        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        // Filter berdasarkan type spesifik (override scope)
        if ($request->filled('type')) {
            $query->byType($request->input('type'));
        }

        $perPage       = min($request->integer('per_page', 20), 100);
        $notifications = $query->paginate($perPage);

        // ✅ unread_count dikembalikan sesuai scope agar badge konsisten
        $unreadCount = match ($scope) {
            'po'    => NotificationService::unreadCountOnlyPo($userId),
            'all'   => NotificationService::unreadCount($userId),
            default => NotificationService::unreadCountGlobal($userId),
        };

        return response()->json([
            'success'      => true,
            'data'         => $notifications,
            'unread_count' => $unreadCount,
            'scope'        => $scope,
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     *
     * ✅ FIX: kembalikan badge per scope
     *   - global_count → untuk Header bell (exclude PO types)
     *   - po_count     → untuk PONotificationBell
     *   - total_count  → semua (untuk referensi)
     */
    public function unreadCount()
    {
        $userId = Auth::id();

        return response()->json([
            'success'      => true,
            // ✅ Badge Header hanya hitung non-PO types
            'unread_count' => NotificationService::unreadCountGlobal($userId),
            // Extra info untuk PONotificationBell dan debugging
            'po_count'     => NotificationService::unreadCountOnlyPo($userId),
            'total_count'  => NotificationService::unreadCount($userId),
        ]);
    }

    /**
     * PATCH /api/notifications/{id}/read
     */
    public function markRead($id)
    {
        $userId       = Auth::id();
        $notification = InternalNotification::forUser($userId)->find($id);

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notifikasi tidak ditemukan',
            ], 404);
        }

        $notification->markAsRead();

        // ✅ Kembalikan unread_count sesuai scope notif yang baru saja dibaca
        $isPoType = in_array($notification->type, InternalNotification::PO_ONLY_TYPES);

        return response()->json([
            'success'      => true,
            'message'      => 'Notifikasi ditandai sudah dibaca',
            'unread_count' => $isPoType
                ? NotificationService::unreadCountOnlyPo($userId)
                : NotificationService::unreadCountGlobal($userId),
            'po_count'     => NotificationService::unreadCountOnlyPo($userId),
            'global_count' => NotificationService::unreadCountGlobal($userId),
        ]);
    }

    /**
     * PATCH /api/notifications/read-all
     *
     * ✅ FIX: support ?scope=global|po|all
     *   - global (default) → hanya tandai non-PO types
     *   - po               → hanya tandai PO types
     *   - all              → tandai semua
     */
    public function markAllRead(Request $request)
    {
        $userId = Auth::id();
        $scope  = $request->input('scope', 'global');

        $count = match ($scope) {
            'po'    => NotificationService::markAllReadOnlyPo($userId),
            'all'   => NotificationService::markAllRead($userId),
            default => NotificationService::markAllReadGlobal($userId),
        };

        return response()->json([
            'success'      => true,
            'message'      => "{$count} notifikasi ditandai sudah dibaca",
            'unread_count' => match ($scope) {
                'po'    => 0,
                'all'   => 0,
                default => 0,
            },
            'po_count'     => NotificationService::unreadCountOnlyPo($userId),
            'global_count' => NotificationService::unreadCountGlobal($userId),
        ]);
    }

    /**
     * DELETE /api/notifications/{id}
     */
    public function destroy($id)
    {
        $userId       = Auth::id();
        $notification = InternalNotification::forUser($userId)->find($id);

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notifikasi tidak ditemukan',
            ], 404);
        }

        $isPoType = in_array($notification->type, InternalNotification::PO_ONLY_TYPES);
        $notification->delete();

        return response()->json([
            'success'      => true,
            'message'      => 'Notifikasi dihapus',
            'unread_count' => $isPoType
                ? NotificationService::unreadCountOnlyPo($userId)
                : NotificationService::unreadCountGlobal($userId),
            'po_count'     => NotificationService::unreadCountOnlyPo($userId),
            'global_count' => NotificationService::unreadCountGlobal($userId),
        ]);
    }
}
