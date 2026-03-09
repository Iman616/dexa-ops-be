<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternalNotification extends Model
{
    protected $table      = 'internal_notifications';
    protected $primaryKey = 'notification_id';

    protected $fillable = [
        'user_id',
        'channel',
        'type',
        'title',
        'message',
        'reference_type',
        'reference_id',
        'scheduled_at',
        'sent_at',
        'read_at',
        'status',
        'meta',
    ];

    protected $casts = [
        'meta'         => 'array',
        'scheduled_at' => 'datetime',
        'sent_at'      => 'datetime',
        'read_at'      => 'datetime',
    ];

    /**
     * Type yang HANYA ditangani PONotificationBell — tidak boleh masuk Header badge.
     */
    public const PO_ONLY_TYPES = [
        'goods_received',
        'goods_received_from_po',
    ];

    /* ─── Relationships ─── */

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /* ─── Scopes ─── */

    public function scopeUnread($q)
    {
        return $q->whereNull('read_at');
    }

    public function scopeForUser($q, int $userId)
    {
        return $q->where('user_id', $userId);
    }

    public function scopeByType($q, string $type)
    {
        return $q->where('type', $type);
    }

    /**
     * ✅ Scope baru: exclude type yang sudah ditangani PONotificationBell.
     * Dipakai untuk badge & list di Header global.
     */
    public function scopeExcludePoTypes($q)
    {
        return $q->whereNotIn('type', self::PO_ONLY_TYPES);
    }

    /**
     * ✅ Scope baru: hanya type PO — untuk PONotificationBell.
     */
    public function scopeOnlyPoTypes($q)
    {
        return $q->whereIn('type', self::PO_ONLY_TYPES);
    }

    /* ─── Helpers ─── */

    public function markAsRead(): void
    {
        if (!$this->read_at) {
            $this->update(['read_at' => now(), 'status' => 'read']);
        }
    }

    public function isRead(): bool
    {
        return !is_null($this->read_at);
    }
}
