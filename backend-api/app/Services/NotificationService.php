<?php

namespace App\Services;

use App\Models\InternalNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Kirim notifikasi ke satu user.
     */
    public static function send(
        int    $userId,
        string $type,
        string $title,
        string $message,
        string $referenceType = null,
        int    $referenceId   = null,
        array  $meta          = [],
        string $channel       = 'system'
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
            Log::error("[NotificationService] Gagal kirim notifikasi ke user {$userId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Kirim notifikasi ke banyak user sekaligus.
     * Menggunakan insert batch agar efisien.
     */
    public static function sendToMany(
        array  $userIds,
        string $type,
        string $title,
        string $message,
        string $referenceType = null,
        int    $referenceId   = null,
        array  $meta          = [],
        string $channel       = 'system'
    ): int {
        $userIds = array_unique(array_filter($userIds));
        if (empty($userIds)) return 0;

        $now  = now();
        $rows = [];

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
                'meta'           => $meta ? json_encode($meta) : null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        try {
            InternalNotification::insert($rows);
            return count($rows);
        } catch (\Exception $e) {
            Log::error("[NotificationService] Gagal batch insert notifikasi: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Kirim ke semua user aktif di company tertentu
     * (bisa difilter berdasarkan role_id).
     */
    public static function sendToCompanyUsers(
        int    $companyId,
        string $type,
        string $title,
        string $message,
        string $referenceType = null,
        int    $referenceId   = null,
        array  $meta          = [],
        array  $roleIds       = [],   // kosong = semua role
        string $channel       = 'system'
    ): int {
        $query = User::where('default_company_id', $companyId)
            ->where('is_active', 1);

        if (!empty($roleIds)) {
            $query->whereIn('role_id', $roleIds);
        }

        $userIds = $query->pluck('user_id')->toArray();

        return self::sendToMany(
            $userIds, $type, $title, $message,
            $referenceType, $referenceId, $meta, $channel
        );
    }

    /**
     * Tandai semua notifikasi user sebagai sudah dibaca.
     */
    public static function markAllRead(int $userId): int
    {
        return InternalNotification::forUser($userId)
            ->unread()
            ->update(['read_at' => now()]);
    }

    /**
     * Hitung notifikasi belum dibaca milik user.
     */
    public static function unreadCount(int $userId): int
    {
        return InternalNotification::forUser($userId)->unread()->count();
    }
}