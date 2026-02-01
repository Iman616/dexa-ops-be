<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';
    protected $primaryKey = 'user_id';

    protected $fillable = [
        'username',
        'email',
        'password',
        'full_name',
        'role_id',
        'phone',
        'is_active',
        'last_login'
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // =============== EXISTING RELATIONSHIPS ===============
    
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function sessions()
    {
        return $this->hasMany(UserSession::class, 'user_id', 'user_id');
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class, 'user_id', 'user_id');
    }

    // ✅ BARU: Relasi untuk Stock Out
    public function processedStockOuts()
    {
        return $this->hasMany(StockOut::class, 'processed_by', 'user_id');
    }

    // =============== SCOPES ===============

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('username', 'LIKE', "%{$search}%")
              ->orWhere('full_name', 'LIKE', "%{$search}%")
              ->orWhere('email', 'LIKE', "%{$search}%");
        });
    }

    // =============== ACCESSORS ===============

    public function getDisplayNameAttribute()
    {
        return $this->full_name ?: $this->username;
    }
}
