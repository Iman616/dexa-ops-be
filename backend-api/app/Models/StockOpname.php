<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class StockOpname extends Model
{
    use SoftDeletes;

    protected $table      = 'stock_opname';
    protected $primaryKey = 'opname_id';

    protected $fillable = [
        'company_id', 'opname_number', 'opname_date',
        'period_year', 'period_month', 'status', 'notes',
        'conducted_by', 'approved_by', 'approved_at',
        'approval_notes', 'created_by',
    ];

    protected $casts = [
        'opname_date'  => 'date',
        'approved_at'  => 'datetime',
        'period_year'  => 'integer',
        'period_month' => 'integer',
    ];

    protected $appends = ['can_edit', 'can_approve', 'total_items', 'total_difference'];

    /* ── Relationships ── */

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function items()
    {
        return $this->hasMany(StockOpnameItem::class, 'opname_id', 'opname_id');
    }

    public function conductedByUser()
    {
        return $this->belongsTo(User::class, 'conducted_by', 'user_id');
    }

    public function approvedByUser()
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    /* ── Accessors ── */

    public function getCanEditAttribute()
    {
        return in_array($this->status, ['draft', 'in_progress']);
    }

    public function getCanApproveAttribute()
    {
        return $this->status === 'completed';
    }

    public function getTotalItemsAttribute()
    {
        return $this->items()->count();
    }

    public function getTotalDifferenceAttribute()
    {
        return $this->items()->sum('difference');
    }

    /* ── Static: Generate nomor opname ── */

    public static function generateOpnameNumber($companyCode)
    {
        $yearMonth = now()->format('Ym');
        $prefix    = "OPN-{$companyCode}-{$yearMonth}-";

        $last = self::where('opname_number', 'LIKE', "{$prefix}%")
            ->orderBy('opname_number', 'desc')
            ->first();

        $seq = $last ? (int) substr($last->opname_number, -4) + 1 : 1;

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    /* ── Boot ── */

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($opname) {
            if (empty($opname->created_by)) {
                $opname->created_by = Auth::id();
            }
        });
    }
}
