<?php

namespace App\Observers;

use App\Models\PurchaseOrder;
use App\Services\DeliveryNoteService;
use Illuminate\Support\Facades\Log;

class PurchaseOrderObserver
{
    protected $deliveryNoteService;

    public function __construct(DeliveryNoteService $deliveryNoteService)
    {
        $this->deliveryNoteService = $deliveryNoteService;
    }

    /**
     * Handle the PurchaseOrder "updated" event.
     * Auto-create delivery note saat PO status berubah jadi approved
     */
    public function updated(PurchaseOrder $purchaseOrder)
    {
        // Cek apakah status berubah menjadi 'approved'
        if ($purchaseOrder->isDirty('status') && $purchaseOrder->status === 'approved') {
            
            // ✅ VALIDATE STOCK FIRST (already done in approve() method, but double check)
            try {
                $purchaseOrder->validateStockForApproval();
            } catch (\Exception $e) {
                Log::warning("PO {$purchaseOrder->po_number} approved but has stock issues: {$e->getMessage()}");
                // Don't block DN creation, just log warning
            }
            
            try {
                // Auto-generate delivery note
                $deliveryNote = $this->deliveryNoteService->createFromPurchaseOrder($purchaseOrder);
                
                Log::info("Auto-created delivery note {$deliveryNote->delivery_note_number} from PO {$purchaseOrder->po_number}");
            } catch (\Exception $e) {
                // Log error tapi jangan block update PO
                Log::error("Failed to auto-create delivery note from PO {$purchaseOrder->po_number}: {$e->getMessage()}");
            }
        }
    }
}