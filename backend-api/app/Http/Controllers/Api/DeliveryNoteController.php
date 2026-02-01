<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
    use Barryvdh\DomPDF\Facade\Pdf;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;


use Illuminate\Support\Facades\DB;

class DeliveryNoteController extends Controller
{
    /**
     * Display a listing of delivery notes
     */
 public function index(Request $request)
{
    $query = DeliveryNote::with(['company', 'createdByUser', 'issuedByUser', 'items']);

    // Filter by company
    if ($request->has('company_id')) {
        $query->where('company_id', $request->company_id);
    }

    // Filter by status
    if ($request->has('status')) {
        $query->where('status', $request->status);
    }

    // Filter by is_received (legacy)
    if ($request->has('is_received')) {
        $query->where('is_received', $request->boolean('is_received'));
    }

    // ✅ FIXED: Search hanya di kolom yang ada
    if ($request->has('search')) {
        $search = $request->search;
        $query->where(function($q) use ($search) {
            $q->where('delivery_note_number', 'like', "%{$search}%")
              ->orWhere('recipient_name', 'like', "%{$search}%")
              ->orWhere('recipient_address', 'like', "%{$search}%")
              ->orWhere('notes', 'like', "%{$search}%");
            
            // ✅ Search in company relation if exists
            $q->orWhereHas('company', function($companyQuery) use ($search) {
                $companyQuery->where('company_name', 'like', "%{$search}%");
            });
        });
    }

    // Filter by date range
    if ($request->has('start_date')) {
        $query->whereDate('delivery_date', '>=', $request->start_date);
    }
    if ($request->has('end_date')) {
        $query->whereDate('delivery_date', '<=', $request->end_date);
    }

    // Sorting
    $sortBy = $request->get('sort_by', 'delivery_date');
    $sortOrder = $request->get('sort_order', 'desc');
    
    $allowedSortFields = ['delivery_date', 'delivery_note_number', 'created_at', 'status'];
    if (in_array($sortBy, $allowedSortFields)) {
        $query->orderBy($sortBy, $sortOrder);
    } else {
        $query->orderBy('delivery_date', 'desc');
    }

    // Pagination
    $perPage = $request->get('per_page', 15);
    $deliveryNotes = $query->paginate($perPage);

    return response()->json([
        'success' => true,
        'message' => 'Delivery notes retrieved successfully',
        'data' => $deliveryNotes
    ], 200);
}



/**
 * Generate PDF Surat Jalan
 * GET /api/delivery-notes/{id}/pdf
 */
public function generatePDF($id)
{
    $deliveryNote = DeliveryNote::with([
        'company', 
        'items.product', 
        'createdByUser'
    ])->find($id);

    if (!$deliveryNote) {
        return response()->json([
            'success' => false,
            'message' => 'Delivery note not found'
        ], 404);
    }

    try {
        // Load view dengan data
        $pdf = Pdf::loadView('pdf.delivery-note', [
            'deliveryNote' => $deliveryNote
        ]);

        // Set paper size dan orientation
        $pdf->setPaper('A4', 'portrait');
        
        // Set options
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'sans-serif'
        ]);

        // Generate filename
        $filename = 'Surat-Jalan-' . $deliveryNote->delivery_note_number . '.pdf';

        // Return PDF untuk download
        return $pdf->download($filename);
        
        // Atau untuk preview di browser, gunakan:
        // return $pdf->stream($filename);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to generate PDF',
            'error' => $e->getMessage()
        ], 500);
    }
}


    /**
     * Store a newly created delivery note
     */
    public function store(Request $request)
    {
       $validator = Validator::make($request->all(), [
    'company_id' => 'required|exists:companies,company_id',
    'delivery_note_number' => 'required|string|max:100|unique:delivery_notes,delivery_note_number',
    'delivery_date' => 'required|date',

    'recipient_name' => 'required|string|max:255',
    'recipient_address' => 'nullable|string',
    'notes' => 'nullable|string',

    'items' => 'required|array|min:1',
    'items.*.product_id' => 'required|exists:products,product_id',
    'items.*.product_name' => 'required|string|max:255',
    'items.*.quantity' => 'required|numeric|min:0.01',
    'items.*.unit' => 'required|string|max:50',
    'items.*.notes' => 'nullable|string',
]);


        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Create delivery note
         $deliveryNote = DeliveryNote::create([
    'company_id' => $request->company_id,
    'delivery_note_number' => $request->delivery_note_number,
    'delivery_date' => $request->delivery_date,

    'recipient_name' => $request->recipient_name,
    'recipient_address' => $request->recipient_address,
    'notes' => $request->notes,

    'created_by' => Auth::id(),
]);


            // Create items
            foreach ($request->items as $item) {
                DeliveryNoteItem::create([
                    'delivery_note_id' => $deliveryNote->delivery_note_id,
                    'product_id' => $item['product_id'],
                    'product_code' => $item['product_code'] ?? null,
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Delivery note created successfully',
                'data' => $deliveryNote->load('items')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create delivery note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified delivery note
     */
    public function show($id)
    {
        $deliveryNote = DeliveryNote::with(['company', 'createdByUser', 'items.product', 'stockIns'])
            ->find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery note retrieved successfully',
            'data' => $deliveryNote
        ], 200);
    }

    /**
     * Update the specified delivery note
     */
    public function update(Request $request, $id)
    {
        $deliveryNote = DeliveryNote::find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found'
            ], 404);
        }

        // Prevent update jika sudah diterima
        if ($deliveryNote->is_received) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update received delivery note'
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'delivery_note_number' => 'sometimes|required|string|max:100|unique:delivery_notes,delivery_note_number,' . $id . ',delivery_note_id',
            'delivery_date' => 'sometimes|required|date',
            'supplier_name' => 'sometimes|required|string|max:255',
            'supplier_address' => 'nullable|string',
            'supplier_phone' => 'nullable|string|max:50',
            'recipient_company' => 'sometimes|required|string|max:255',
            'recipient_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $deliveryNote->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Delivery note updated successfully',
                'data' => $deliveryNote->load('items')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update delivery note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified delivery note
     */
    public function destroy($id)
    {
        $deliveryNote = DeliveryNote::find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found'
            ], 404);
        }

        // Prevent delete jika sudah diterima
        if ($deliveryNote->is_received) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete received delivery note'
            ], 409);
        }

        try {
            $deliveryNote->delete();

            return response()->json([
                'success' => true,
                'message' => 'Delivery note deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete delivery note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark delivery note as received
     * POST /api/delivery-notes/{id}/mark-received
     */
    public function markAsReceived(Request $request, $id)
    {
        $deliveryNote = DeliveryNote::find($id);

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found'
            ], 404);
        }

        if ($deliveryNote->is_received) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note already marked as received'
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'receiver_signature' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $deliveryNote->markAsReceived($request->receiver_signature);

            return response()->json([
                'success' => true,
                'message' => 'Delivery note marked as received successfully',
                'data' => $deliveryNote
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark delivery note as received',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function issue(Request $request, $id)
{
    $deliveryNote = DeliveryNote::find($id);

    if (!$deliveryNote) {
        return response()->json([
            'success' => false,
            'message' => 'Delivery note not found'
        ], 404);
    }

    if ($deliveryNote->isIssued()) {
        return response()->json([
            'success' => false,
            'message' => 'Delivery note already issued'
        ], 409);
    }

    $validator = Validator::make($request->all(), [
        'signed_name'     => 'required|string|max:100',
        'signed_position' => 'required|string|max:100',
        'signed_city'     => 'required|string|max:50',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors()
        ], 422);
    }

    try {
        $deliveryNote->issue($request->only([
            'signed_name',
            'signed_position',
            'signed_city'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Delivery note issued successfully',
            'data'    => $deliveryNote->fresh()
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}


    /**
     * Get delivery note by number
     */
    public function getByNumber($deliveryNoteNumber)
    {
        $deliveryNote = DeliveryNote::where('delivery_note_number', $deliveryNoteNumber)
            ->with(['company', 'items.product', 'stockIns'])
            ->first();

        if (!$deliveryNote) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery note not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery note retrieved successfully',
            'data' => $deliveryNote
        ], 200);
    }
}
