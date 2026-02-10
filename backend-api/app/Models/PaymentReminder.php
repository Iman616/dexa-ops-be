<?php
// app/Models/PaymentReminder.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentReminder extends Model
{
    protected $table = 'payment_reminders';
    protected $primaryKey = 'reminder_id';
    
    protected $fillable = [
        'reference_type',
        'reference_id',
        'reminder_type',
        'due_date',
        'reminder_date',
        'status',
        'sent_at',
        'sent_to',
        'message',
    ];

    protected $casts = [
        'due_date' => 'date',
        'reminder_date' => 'date',
        'sent_at' => 'datetime',
        'sent_to' => 'array',
    ];

    // Polymorphic relationship
    public function reference(): MorphTo
    {
        return $this->morphTo('reference', 'reference_type', 'reference_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    public function scopeDueToday($query)
    {
        return $query->where('reminder_date', now()->toDateString());
    }

    // Methods
    public function markAsSent(array $userIds): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
            'sent_to' => $userIds,
        ]);
    }
}
