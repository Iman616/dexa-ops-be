<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTerm extends Model
{
    protected $table      = 'document_terms';
    protected $primaryKey = 'term_id';

    protected $fillable = [
        'company_id',
        'document_type',
        'term_content',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function scopeForDocument($query, string $type)
    {
        return $query->where('document_type', $type)
                     ->where('is_active', true)
                     ->orderBy('sort_order');
    }
}
