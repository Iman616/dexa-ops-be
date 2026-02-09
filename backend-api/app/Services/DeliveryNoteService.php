<?php

namespace App\Services;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\PurchaseOrder;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class DeliveryNoteService
{
    /**
     * Auto-generate Delivery Note dari Purchase Order yang approved
     */
    public function createFromPurchaseOrder(PurchaseOrder $purchaseOrder)
    {
        // Validasi: PO harus approved
        if ($purchaseOrder->status !== 'approved') {
            throw new \Exception('Only approved purchase orders can be converted to delivery note');
        }

        // Validasi: PO belum punya delivery note
        if ($purchaseOrder->deliveryNotes()->exists()) {
            throw new \Exception('Purchase order already has delivery note');
        }

        // Validasi: PO harus punya items
        if (!$purchaseOrder->items()->exists()) {
            throw new \Exception('Purchase order has no items');
        }

        return DB::transaction(function () use ($purchaseOrder) {
            // Load relationships
            $purchaseOrder->load(['company', 'customer', 'items.product', 'invoices']);

            // Generate delivery note number
            $deliveryNoteNumber = $this->generateDeliveryNoteNumber(
                $purchaseOrder->company->company_code
            );

            // Ambil invoice_id jika ada
            $invoiceId = $purchaseOrder->invoices()->first()?->invoice_id;

            // Create delivery note
            $deliveryNote = DeliveryNote::create([
                'company_id' => $purchaseOrder->company_id,
                'invoice_id' => $invoiceId,
                'po_id' => $purchaseOrder->po_id,
                'quotation_id' => $purchaseOrder->quotation_id,
                'delivery_note_number' => $deliveryNoteNumber,
                'delivery_date' => now()->toDateString(),
                'recipient_name' => $purchaseOrder->customer->customer_name,
                'recipient_address' => $purchaseOrder->customer->address,
                'notes' => "Auto-generated from PO: {$purchaseOrder->po_number}",
                'status' => 'draft',
                'created_by' => Auth::id() ?? 1,
            ]);

            // Copy items dari PO ke delivery note
            foreach ($purchaseOrder->items as $poItem) {
                DeliveryNoteItem::create([
                    'delivery_note_id' => $deliveryNote->delivery_note_id,
                    'product_id' => $poItem->product_id,
                    'product_code' => $poItem->product?->product_code,
                    'product_name' => $poItem->product_name,
                    'quantity' => $poItem->quantity,
                    'unit' => $poItem->unit,
                    'notes' => $poItem->notes,
                ]);
            }

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id() ?? 1,
                'action' => 'create',
                'module' => 'delivery_notes',
                'record_id' => $deliveryNote->delivery_note_id,
                'description' => "Delivery Note {$deliveryNoteNumber} auto-created from PO {$purchaseOrder->po_number}",
            ]);

            return $deliveryNote->load('items', 'purchaseOrder', 'quotation', 'company');
        });
    }

    /**
     * Generate nomor delivery note
     * Format: SJ-{COMPANY_CODE}-{YYYYMM}-{SEQUENCE}
     * Contoh: SJ-DXM-202602-0001
     */
    public function generateDeliveryNoteNumber($companyCode)
    {
        $prefix = "SJ-{$companyCode}-" . now()->format('Ym') . '-';
        
        // Get last number untuk bulan ini
        $lastDeliveryNote = DeliveryNote::where('delivery_note_number', 'like', "{$prefix}%")
            ->orderBy('delivery_note_number', 'desc')
            ->first();

        if ($lastDeliveryNote) {
            // Extract nomor sequence dari nomor terakhir
            $lastNumber = (int) substr($lastDeliveryNote->delivery_note_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}
