<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    use HasFactory;

    protected $table = 'user_sessions';
    protected $primaryKey = 'session_id';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'session_token',
        'selected_company_id', // ⭐ BARU
        'ip_address',
        'user_agent',
        'login_at',
        'logout_at',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'login_at' => 'datetime',
        'logout_at' => 'datetime',
    ];

    /* ================= RELATIONSHIPS ================= */

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * ⭐ Selected company dalam session
     */
    public function selectedCompany()
    {
        return $this->belongsTo(Company::class, 'selected_company_id', 'company_id');
    }

    /* ================= METHODS ================= */

    /**
     * ⭐ Switch company dalam session
     */
    public function switchCompany($companyId)
    {
        $this->update(['selected_company_id' => $companyId]);
    }
}
