<?php
// app/Models/InternalNotification.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternalNotification extends Model
{
    protected $table = 'internal_notifications';
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
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at')->where('status', 'sent');
    }

    public function markAsSent(): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function markAsRead(): void
    {
        $this->update([
            'status' => 'read',
            'read_at' => now(),
        ]);
    }

    public function dismiss(): void
    {
        $this->update([
            'status' => 'dismissed',
        ]);
    }
}
