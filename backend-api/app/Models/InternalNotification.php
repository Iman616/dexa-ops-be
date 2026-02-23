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

    /* ─── Helpers ─── */

    public function markAsRead(): void
    {
        if (!$this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }

    public function isRead(): bool
    {
        return !is_null($this->read_at);
    }
}