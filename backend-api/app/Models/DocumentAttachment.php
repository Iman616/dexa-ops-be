<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class DocumentAttachment extends Model
{
    use HasFactory;

    protected $table = 'document_attachments';
    protected $primaryKey = 'attachment_id'; // ✅ Ubah dari attachmentid
    public $incrementing = true;
    protected $keyType = 'integer';

    protected $fillable = [
        'reference_type',     
        'reference_id',       
        'document_type',      
        'file_name',         
        'file_path',           
        'file_size',          
        'mime_type',          
        'uploaded_by',         
    ];

    protected $casts = [
        'created_at' => 'datetime',  // ✅ Gunakan timestamps default Laravel
        'file_size' => 'integer',
    ];

    // ✅ Konstanta untuk reference_type (tabel mana yang direferensikan)
    const REFERENCE_TYPES = [
        'quotation',
        'po', 
        'invoice', 
        'delivery_note',
        'payment',
        'stock_out',        // ✅ Tambahan untuk barang keluar
        'stock_in'
    ];

    // ✅ Konstanta untuk document_type (jenis file apa)
    const DOCUMENT_TYPES = [
        'signature',        // TTD penerima barang
        'photo',           // Foto bukti penerimaan
        'document',        // Dokumen pendukung (PDF surat jalan, dll)
        'invoice',
        'receipt'
    ];

    /* ================= RELATIONSHIPS ================= */

    /**
     * Belongs to User (uploader)
     */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by', 'user_id');
    }

    /**
     * ✅ Polymorphic relationship ke StockOut
     */
    public function stockOut()
    {
        return $this->belongsTo(StockOut::class, 'reference_id', 'stock_out_id')
                    ->where('reference_type', 'stock_out');
    }

    /**
     * ✅ Polymorphic relationship ke DeliveryNote
     */
    public function deliveryNote()
    {
        return $this->belongsTo(DeliveryNote::class, 'reference_id', 'delivery_note_id')
                    ->where('reference_type', 'delivery_note');
    }

    /* ================= ACCESSORS ================= */

    /**
     * Get full URL untuk akses file
     */
    public function getUrlAttribute()
    {
        return Storage::url($this->file_path);
    }

    /**
     * Check apakah file adalah gambar
     */
    public function getIsImageAttribute()
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    /**
     * ✅ Check apakah file adalah PDF
     */
    public function getIsPdfAttribute()
    {
        return $this->mime_type === 'application/pdf';
    }

    /**
     * ✅ Get formatted file size (KB/MB)
     */
    public function getFormattedSizeAttribute()
    {
        $bytes = $this->file_size;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        return round($bytes / 1024, 2) . ' KB';
    }

    /* ================= SCOPES ================= */

    /**
     * Scope untuk filter per reference type dan ID
     */
    public function scopeByReference($query, $type, $id)
    {
        return $query->where('reference_type', $type)
                     ->where('reference_id', $id);
    }

    /**
     * ✅ Scope untuk stock out documents
     */
    public function scopeStockOutDocuments($query, $stockOutId)
    {
        return $query->where('reference_type', 'stock_out')
                     ->where('reference_id', $stockOutId);
    }

    /**
     * ✅ Scope untuk delivery note documents
     */
    public function scopeDeliveryNoteDocuments($query, $deliveryNoteId)
    {
        return $query->where('reference_type', 'delivery_note')
                     ->where('reference_id', $deliveryNoteId);
    }

    /**
     * ✅ Scope untuk signature only
     */
    public function scopeSignatures($query)
    {
        return $query->where('document_type', 'signature');
    }

    /**
     * ✅ Scope untuk photos only
     */
    public function scopePhotos($query)
    {
        return $query->where('document_type', 'photo');
    }

    /* ================= HELPER METHODS ================= */

    /**
     * ✅ Delete file from storage saat model dihapus
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($attachment) {
            if (Storage::disk('public')->exists($attachment->file_path)) {
                Storage::disk('public')->delete($attachment->file_path);
            }
        });
    }

    /**
     * ✅ Get download link
     */
    public function getDownloadUrl()
    {
        return route('attachments.download', $this->attachment_id);
    }

    /**
     * ✅ Validate file type
     */
    public static function validateFileType($mimeType)
    {
        $allowed = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];

        return in_array($mimeType, $allowed);
    }
}