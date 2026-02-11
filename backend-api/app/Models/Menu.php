<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    protected $table = 'menus';
    protected $primaryKey = 'menu_id'; // ✅ Sesuai database

    protected $fillable = [
        'menu_key',
        'menu_name',
        'icon',
        'url',
        'sort_order',
        'parent_id',
    ];

    public $timestamps = false; // ✅ Table ga punya created_at/updated_at

    /* ================= RELATIONSHIPS ================= */

    /**
     * Parent menu
     */
    public function parent()
    {
        return $this->belongsTo(Menu::class, 'parent_id', 'menu_id');
    }

    /**
     * Children menus
     */
    public function children()
    {
        return $this->hasMany(Menu::class, 'parent_id', 'menu_id')
                    ->orderBy('sort_order');
    }

    /**
     * Role permissions untuk menu ini
     */
    public function rolePermissions()
    {
        return $this->hasMany(RolePermission::class, 'menu_id', 'menu_id');
    }
}
