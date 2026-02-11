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
        'default_company_id', // ⭐ BARU
        'is_active',
        'last_login'
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ================= RELATIONSHIPS ================= */
    
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    /**
     * ⭐ Companies yang bisa diakses user (Many-to-Many via user_companies)
     */
    public function companies()
    {
        return $this->belongsToMany(
            Company::class,
            'user_companies',
            'user_id',
            'company_id',
            'user_id',
            'company_id'
       )
    ->withPivot('is_default', 'created_at', 'updated_at')
    ->withTimestamps()
    ->select(['companies.company_id', 'companies.company_code', 'companies.company_name']); // ✅ Qualify here
}

    /**
     * ⭐ Default company user
     */
    public function defaultCompany()
    {
        return $this->belongsTo(Company::class, 'default_company_id', 'company_id');
    }

    public function sessions()
    {
        return $this->hasMany(UserSession::class, 'user_id', 'user_id');
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class, 'user_id', 'user_id');
    }

    public function processedStockOuts()
    {
        return $this->hasMany(StockOut::class, 'processed_by', 'user_id');
    }

    /* ================= SCOPES ================= */

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

    /* ================= ACCESSORS ================= */

    public function getDisplayNameAttribute()
    {
        return $this->full_name ?: $this->username;
    }

    /**
     * ⭐ Get list companies untuk frontend
     */
    public function getAccessibleCompaniesAttribute()
    {
        return $this->companies->map(function($company) {
            return [
                'company_id' => $company->company_id,
                'company_code' => $company->company_code,
                'company_name' => $company->company_name,
                'is_default' => $this->default_company_id == $company->company_id,
            ];
        });
    }

      public function hasPermission(string $menuKey, string $action = 'read'): bool
    {
        return $this->role->hasPermission($menuKey, $action);
    }

    /**
     * ✅ Get all user permissions
     */
    public function getPermissionsAttribute()
    {
        return $this->role->permissions()
            ->with('menu')
            ->get()
            ->map(function ($permission) {
                return [
                    'menu_id' => $permission->menu_id,
                    'menu_key' => $permission->menu->menu_key,
                    'menu_name' => $permission->menu->menu_name,
                    'can_create' => $permission->can_create,
                    'can_read' => $permission->can_read,
                    'can_update' => $permission->can_update,
                    'can_delete' => $permission->can_delete,
                ];
            });
    }

    /* ================= METHODS ================= */

    /**
     * ⭐ Check apakah user punya akses ke company
     */
    public function hasAccessToCompany($companyId)
    {
        return $this->companies()->where('company_id', $companyId)->exists();
    }

    /**
     * ⭐ Switch ke company lain
     */
    public function switchToCompany($companyId)
    {
        if (!$this->hasAccessToCompany($companyId)) {
            throw new \Exception('User does not have access to this company');
        }

        $this->update(['default_company_id' => $companyId]);
        return $this;
    }

    /**
     * ⭐ Assign multiple companies ke user
     */
    public function assignCompanies(array $companyIds)
    {
        $this->companies()->sync($companyIds);
    }

    /**
     * ⭐ Add 1 company
     */
    public function addCompany($companyId)
    {
        if (!$this->hasAccessToCompany($companyId)) {
            $this->companies()->attach($companyId);
        }
    }

    /**
     * ⭐ Remove company
     */
    public function removeCompany($companyId)
    {
        $this->companies()->detach($companyId);
        
        if ($this->default_company_id == $companyId) {
            $this->update(['default_company_id' => null]);
        }
    }

    
}
