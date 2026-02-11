<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    // ✅ FIX: Table name sesuai database
    protected $table = 'role_menus'; // BUKAN 'role_permissions'
    protected $primaryKey = 'role_menu_id';

    protected $fillable = [
        'role_id',
        'menu_id',
        'can_create',
        'can_read',
        'can_update',
        'can_delete',
    ];

    protected $casts = [
        'can_create' => 'boolean',
        'can_read' => 'boolean',
        'can_update' => 'boolean',
        'can_delete' => 'boolean',
    ];

    public $timestamps = true;

    /* ================= RELATIONSHIPS ================= */

    /**
     * Role yang punya permission ini
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    /**
     * Menu yang di-reference
     */
    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_id', 'menu_id');
    }
}
