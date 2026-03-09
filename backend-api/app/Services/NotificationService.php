<?php

namespace App\Services;

use App\Models\InternalNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Kirim notifikasi ke satu user.
     */
    public static function send(
        int     $userId,
        string  $type,
        string  $title,
        string  $message,
        ?string $referenceType = null,
        ?int    $referenceId   = null,
        array   $meta          = [],
        string  $channel       = 'system'
    ): ?InternalNotification {
        try {
            return InternalNotification::create([
                'user_id'        => $userId,
                'channel'        => $channel,
                'type'           => $type,
                'title'          => $title,
                'message'        => $message,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'status'         => 'sent',
                'sent_at'        => now(),
                'meta'           => $meta ?: null,
            ]);
        } catch (\Exception $e) {
            Log::error("[NotificationService] Gagal kirim notif ke user {$userId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Kirim notifikasi ke banyak user — batch insert.
     */
    public static function sendToMany(
        array   $userIds,
        string  $type,
        string  $title,
        string  $message,
        ?string $referenceType = null,
        ?int    $referenceId   = null,
        array   $meta          = [],
        string  $channel       = 'system'
    ): int {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (empty($userIds)) return 0;

        $now      = now();
        $metaJson = $meta ? json_encode($meta) : null;
        $rows     = [];

        foreach ($userIds as $userId) {
            $rows[] = [
                'user_id'        => $userId,
                'channel'        => $channel,
                'type'           => $type,
                'title'          => $title,
                'message'        => $message,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'status'         => 'sent',
                'sent_at'        => $now,
                'read_at'        => null,
                'meta'           => $metaJson,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        try {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('internal_notifications')->insert($chunk);
            }
            return count($rows);
        } catch (\Exception $e) {
            Log::error("[NotificationService] Gagal batch insert: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Kirim ke semua user aktif di company tertentu.
     */
    public static function sendToCompanyUsers(
        int     $companyId,
        string  $type,
        string  $title,
        string  $message,
        ?string $referenceType = null,
        ?int    $referenceId   = null,
        array   $meta          = [],
        array   $roleIds       = [],
        string  $channel       = 'system'
    ): int {
        $query = DB::table('users')
            ->where('default_company_id', $companyId)
            ->where('is_active', 1);

        if (!empty($roleIds)) {
            $query->whereIn('role_id', $roleIds);
        }

        $userIds = $query->pluck('user_id')->toArray();
        if (empty($userIds)) return 0;

        return self::sendToMany(
            $userIds, $type, $title, $message,
            $referenceType, $referenceId, $meta, $channel
        );
    }

    /**
     * Kirim ke semua Super Admin (role_id = 1).
     */
    public static function sendToSuperAdmins(
        string  $type,
        string  $title,
        string  $message,
        ?string $referenceType = null,
        ?int    $referenceId   = null,
        array   $meta          = [],
        string  $channel       = 'system'
    ): int {
        $userIds = DB::table('users')
            ->where('role_id', 1)
            ->where('is_active', 1)
            ->pluck('user_id')
            ->toArray();

        return self::sendToMany(
            $userIds, $type, $title, $message,
            $referenceType, $referenceId, $meta, $channel
        );
    }

    /* =========================================================
     * UNREAD COUNT — 3 variant sesuai scope
     * ========================================================= */

    /**
     * Total semua unread (semua type) — untuk referensi / scope=all.
     */
    public static function unreadCount(int $userId): int
    {
        return DB::table('internal_notifications')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->whereIn('status', ['sent', 'pending'])
            ->count();
    }

    /**
     * ✅ Unread EXCLUDE PO types — untuk badge Header (NotificationBell).
     */
    public static function unreadCountGlobal(int $userId): int
    {
        return DB::table('internal_notifications')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->whereIn('status', ['sent', 'pending'])
            ->whereNotIn('type', InternalNotification::PO_ONLY_TYPES)
            ->count();
    }

    /**
     * ✅ Unread HANYA PO types — untuk badge PONotificationBell.
     */
    public static function unreadCountOnlyPo(int $userId): int
    {
        return DB::table('internal_notifications')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->whereIn('status', ['sent', 'pending'])
            ->whereIn('type', InternalNotification::PO_ONLY_TYPES)
            ->count();
    }

    /* =========================================================
     * MARK ALL READ — 3 variant sesuai scope
     * ========================================================= */

    /**
     * Tandai semua notifikasi sebagai dibaca (semua type).
     */
    public static function markAllRead(int $userId): int
    {
        return DB::table('internal_notifications')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'status' => 'read']);
    }

    /**
     * ✅ Tandai semua KECUALI PO types (untuk tombol "Tandai Semua" di Header).
     */
    public static function markAllReadGlobal(int $userId): int
    {
        return DB::table('internal_notifications')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->whereNotIn('type', InternalNotification::PO_ONLY_TYPES)
            ->update(['read_at' => now(), 'status' => 'read']);
    }

    /**
     * ✅ Tandai semua PO types saja (untuk tombol "Tandai Semua" di PONotificationBell).
     */
    public static function markAllReadOnlyPo(int $userId): int
    {
        return DB::table('internal_notifications')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->whereIn('type', InternalNotification::PO_ONLY_TYPES)
            ->update(['read_at' => now(), 'status' => 'read']);
    }
}
