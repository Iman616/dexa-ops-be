<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityType extends Model
{
    use HasFactory;

    protected $table      = 'activity_types';
    protected $primaryKey = 'activity_type_id';

    protected $fillable = [
        'type_name',
        'type_code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ================================================================
    // Role → type_code yang boleh dilihat
    // ================================================================

    /**
     * Role ID yang hanya boleh lihat RETAIL + ONLINE_SHOP
     * (Sales/Marketing)
     */
    public const RETAIL_ROLE_IDS = [3];

    /**
     * Role ID yang hanya boleh lihat TENDER
     * (Tender Manager, dsb — tambahkan role_id sesuai kebutuhan)
     */
    public const TENDER_ROLE_IDS = [7];

    /**
     * Role ID yang bisa lihat SEMUA
     * (Super Admin, Admin)
     */
    public const FULL_ACCESS_ROLE_IDS = [1, 2];

    /**
     * Map: type_code yang termasuk kelompok "retail"
     */
    public const RETAIL_TYPE_CODES = ['RETAIL', 'ONLINE_SHOP'];

    /**
     * Map: type_code yang termasuk kelompok "tender"
     */
    public const TENDER_TYPE_CODES = ['TENDER'];

    // ================================================================
    // Relationships
    // ================================================================

    public function quotations()
    {
        return $this->hasMany(Quotation::class, 'activity_type_id', 'activity_type_id');
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'activity_type_id', 'activity_type_id');
    }

    // ================================================================
    // Scopes
    // ================================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Hanya tampilkan RETAIL + ONLINE_SHOP */
    public function scopeRetailOnly($query)
    {
        return $query->whereIn('type_code', self::RETAIL_TYPE_CODES);
    }

    /** Hanya tampilkan TENDER */
    public function scopeTenderOnly($query)
    {
        return $query->whereIn('type_code', self::TENDER_TYPE_CODES);
    }

    /**
     * ✅ Scope utama — filter otomatis berdasarkan role_id user.
     *
     * Usage:  ActivityType::forRole($user->role_id)->active()->get()
     */
    public function scopeForRole($query, int $roleId)
    {
        // Full access: tampilkan semua
        if (in_array($roleId, self::FULL_ACCESS_ROLE_IDS)) {
            return $query;
        }

        // Sales/Marketing: hanya RETAIL + ONLINE_SHOP
        if (in_array($roleId, self::RETAIL_ROLE_IDS)) {
            return $query->whereIn('type_code', self::RETAIL_TYPE_CODES);
        }

        // Tender: hanya TENDER
        if (in_array($roleId, self::TENDER_ROLE_IDS)) {
            return $query->whereIn('type_code', self::TENDER_TYPE_CODES);
        }

        // Default (Purchasing, Warehouse, dll): tampilkan semua
        // Sesuaikan jika ingin lebih restrictive
        return $query;
    }

    // ================================================================
    // Static helpers
    // ================================================================

    /**
     * Cek apakah role ini termasuk "pengguna tender"
     */
    public static function isTenderRole(int $roleId): bool
    {
        return in_array($roleId, self::TENDER_ROLE_IDS)
            || in_array($roleId, self::FULL_ACCESS_ROLE_IDS);
    }

    /**
     * Cek apakah role ini termasuk "pengguna retail"
     */
    public static function isRetailRole(int $roleId): bool
    {
        return in_array($roleId, self::RETAIL_ROLE_IDS)
            || in_array($roleId, self::FULL_ACCESS_ROLE_IDS);
    }
}