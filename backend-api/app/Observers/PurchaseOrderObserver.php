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
