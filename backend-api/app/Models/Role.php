<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;
    
    protected $table = 'roles';
    protected $primaryKey = 'role_id';
    public $timestamps = false;
    
    protected $fillable = [
        'role_name',
        'description'
    ];
    
    protected $casts = [
        'created_at' => 'datetime',
    ];
    
    // Relationships
    public function users()
    {
        return $this->hasMany(User::class, 'role_id', 'role_id');
    }

    // ✅ Scopes
    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('role_name', 'LIKE', "%{$search}%")
              ->orWhere('description', 'LIKE', "%{$search}%");
        });
    }

    public function scopeWithActiveUsers($query)
    {
        return $query->withCount(['users' => function($q) {
            $q->where('is_active', true);
        }]);
    }
}
