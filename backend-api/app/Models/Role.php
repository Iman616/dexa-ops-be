<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';
    protected $primaryKey = 'role_id';

    protected $fillable = [
        'role_name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /* ================= RELATIONSHIPS ================= */

    /**
     * Users dengan role ini
     */
    public function users()
    {
        return $this->hasMany(User::class, 'role_id', 'role_id');
    }

    /**
     * ✅ Permissions untuk role ini
     */
    public function permissions()
    {
        return $this->hasMany(RolePermission::class, 'role_id', 'role_id');
    }

    /* ================= HELPER METHODS ================= */

    /**
     * Check if role has permission for specific menu & action
     */
    public function hasPermission(string $menuKey, string $action = 'read'): bool
    {
        return $this->permissions()
            ->whereHas('menu', function ($query) use ($menuKey) {
                $query->where('menu_key', $menuKey);
            })
            ->where("can_{$action}", true)
            ->exists();
    }

    /**
     * Get all accessible menu keys for this role
     */
    public function getAccessibleMenusAttribute()
    {
        return $this->permissions()
            ->where('can_read', true)
            ->with('menu')
            ->get()
            ->pluck('menu.menu_key')
            ->toArray();
    }
}
