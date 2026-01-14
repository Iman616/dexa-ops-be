<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $table = 'products';
    protected $primaryKey = 'product_id';

    protected $fillable = [
        'product_code',
        'product_name',
        'category',
        'unit',
        'is_precursor',
        'description',
    ];

    protected $casts = [
        'is_precursor' => 'boolean',
    ];

    // Relationships
    public function stockIns()
    {
        return $this->hasMany(StockIn::class, 'product_id', 'product_id');
    }

    public function stockBatches()
    {
        return $this->hasMany(StockBatch::class, 'product_id', 'product_id');
    }

    // Scopes
    public function scopePrecursor($query)
    {
        return $query->where('is_precursor', true);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('product_code', 'like', "%{$search}%")
              ->orWhere('product_name', 'like', "%{$search}%")
              ->orWhere('category', 'like', "%{$search}%");
        });
    }

    public function scopeFilterByCategory($query, $category)
    {
        if ($category) {
            return $query->where('category', $category);
        }
        return $query;
    }
}
