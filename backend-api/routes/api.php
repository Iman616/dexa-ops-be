<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EndingStockController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierPoController;
use App\Http\Controllers\Api\StockBatchController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DeliveryNoteController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StockInController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\StockOutController;
use App\Http\Controllers\Api\StockOpeningController;
use App\Http\Controllers\Api\OcrController;
use App\Http\Controllers\Api\QuotationPDFController;
use App\Http\Controllers\Api\ProformaInvoiceController;
use App\Http\Controllers\Api\ProformaInvoicePDFController;
use App\Http\Controllers\Api\DocumentAttachmentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\SupplierPurchaseOrderController;
use App\Http\Controllers\Api\SupplierDeliveryNoteController;
use App\Http\Controllers\Api\TaxInvoiceController;

use App\Http\Controllers\Api\InternalNotificationController;

use App\Http\Controllers\Api\TenderDocumentController;

use App\Http\Controllers\Api\ActivityTypeController;
use App\Http\Controllers\Api\SupplierInvoiceController;
use App\Http\Controllers\Api\StockReturnController;
use App\Http\Controllers\Api\AgentPaymentController;

use App\Http\Controllers\Api\TenderProjectDetailController;




/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES (NO AUTH)
|--------------------------------------------------------------------------
*/

Route::get('/test', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'API is working!',
        'timestamp' => now()->toDateTimeString()
    ]);
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
});

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES (REQUIRE BEARER TOKEN)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/switch-company', [AuthController::class, 'switchCompany']); // ⭐ NEW
});

    // ==================== COMPANY ROUTES ====================
    Route::apiResource('companies', CompanyController::class);
    Route::prefix('companies/{company}')->group(function () {
        Route::get('statistics', [CompanyController::class, 'statistics']);
        Route::get('users', [CompanyController::class, 'users']);
        Route::post('toggle-active', [CompanyController::class, 'toggleActive']);
    });

   


    // ==================== PRODUCTS ====================
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::post('/', [ProductController::class, 'store']);
        Route::get('/categories', [ProductController::class, 'categories']);
        Route::get('/{id}', [ProductController::class, 'show']);
        Route::put('/{id}', [ProductController::class, 'update']);
        Route::delete('/{id}', [ProductController::class, 'destroy']);
        Route::post('/export', [ProductController::class, 'export']);
        Route::post('/import', [ProductController::class, 'import']);
        Route::get('/{id}/batches', [ProductController::class, 'batches']);
        Route::get('/{id}/purchase-history', [ProductController::class, 'purchaseHistory']);
        Route::post('/{id}/update-purchase-price', [ProductController::class, 'updatePurchasePriceFromLastPO']);
    });

    // ==================== CUSTOMERS ====================
    Route::prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'index']);
        Route::post('/', [CustomerController::class, 'store']);
        Route::get('/{id}', [CustomerController::class, 'show']);
        Route::match(['put', 'patch'], '/{id}', [CustomerController::class, 'update']);
        Route::delete('/{id}', [CustomerController::class, 'destroy']);
    });

    // ==================== QUOTATIONS ====================
    Route::prefix('quotations')->group(function () {
        Route::get('/', [QuotationController::class, 'index']);
        Route::post('/', [QuotationController::class, 'store']);
        Route::get('/{id}', [QuotationController::class, 'show']);
        Route::put('/{id}', [QuotationController::class, 'update']);
        Route::delete('/{id}', [QuotationController::class, 'destroy']);
        Route::patch('/{id}/status', [QuotationController::class, 'updateStatus']);
        Route::post('/{id}/issue', [QuotationController::class, 'issue']);
        
        // PDF
        Route::get('/{id}/pdf', [QuotationPDFController::class, 'generate']);
        Route::get('/{id}/pdf/download', [QuotationPDFController::class, 'download']);
         Route::post('/{id}/convert-to-po', [QuotationController::class, 'convertToPurchaseOrder']);
    });

     Route::prefix('dashboard')->group(function () {
    Route::get('/stats', [DashboardController::class, 'getStats']);
    Route::get('/recent-transactions', [DashboardController::class, 'getRecentTransactions']);
    Route::get('/monthly-revenue', [DashboardController::class, 'getMonthlyRevenue']);
    Route::get('/payment-methods', [DashboardController::class, 'getPaymentMethodStats']);
    Route::get('/top-customers', [DashboardController::class, 'getTopCustomers']);
    Route::get('/invoice-status', [DashboardController::class, 'getInvoiceStatusDistribution']);
    Route::get('/weekly-revenue', [DashboardController::class, 'getWeeklyRevenue']);
});

Route::prefix('internal-notifications')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [InternalNotificationController::class, 'index']);
    Route::post('/mark-all-read', [InternalNotificationController::class, 'markAllAsRead']);
    Route::post('/{notification}/read', [InternalNotificationController::class, 'markAsRead']);
});

Route::prefix('tender-project-details')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [TenderProjectDetailController::class, 'index']);
    Route::get('/statistics', [TenderProjectDetailController::class, 'statistics']);
    Route::post('/from-po/{po_id}', [TenderProjectDetailController::class, 'storeFromPO']);
    Route::get('/by-po/{po_id}', [TenderProjectDetailController::class, 'showByPO']);
    Route::get('/{id}', [TenderProjectDetailController::class, 'show']);
    Route::put('/{id}', [TenderProjectDetailController::class, 'update']);
});

  // Supplier Invoices
    Route::prefix('supplier-invoices')->group(function () {
        Route::get('/', [SupplierInvoiceController::class, 'indexInvoices']);
        Route::get('/{id}', [SupplierInvoiceController::class, 'showInvoice']);
        Route::post('/', [SupplierInvoiceController::class, 'storeInvoice']);
        Route::put('/{id}', [SupplierInvoiceController::class, 'updateInvoice']);
        Route::delete('/{id}', [SupplierInvoiceController::class, 'destroyInvoice']);
        Route::get('/{id}/download', [SupplierInvoiceController::class, 'downloadInvoiceFile']);
    });

    // Supplier Payments
    Route::prefix('supplier-payments')->group(function () {
        Route::get('/', [SupplierInvoiceController::class, 'indexPayments']);
        Route::get('/{id}', [SupplierInvoiceController::class, 'showPayment']);
        Route::post('/', [SupplierInvoiceController::class, 'storePayment']);
        Route::post('/{id}/approve', [SupplierInvoiceController::class, 'approvePayment']);
        Route::post('/{id}/cancel', [SupplierInvoiceController::class, 'cancelPayment']);
        Route::delete('/{id}', [SupplierInvoiceController::class, 'destroyPayment']);
        Route::get('/{id}/download-proof', [SupplierInvoiceController::class, 'downloadPaymentProof']);
    });



 Route::prefix('purchase-orders')->group(function () {
    Route::get('/', [PurchaseOrderController::class, 'index']);
    Route::post('/', [PurchaseOrderController::class, 'store']);
    Route::get('/{id}', [PurchaseOrderController::class, 'show']);
    Route::put('/{id}', [PurchaseOrderController::class, 'update']);
    Route::delete('/{id}', [PurchaseOrderController::class, 'destroy']);
    
    Route::patch('/{id}/status', [PurchaseOrderController::class, 'updateStatus']);
    Route::post('/{id}/issue', [PurchaseOrderController::class, 'issue']);
    
    Route::get('/{id}/pdf', [PurchaseOrderController::class, 'generatePdf']);
    Route::get('/{id}/download-po-file', [PurchaseOrderController::class, 'downloadPoFile']);
    
    Route::post('/{id}/upload-po-customer-file', [PurchaseOrderController::class, 'uploadPoCustomerFile']);
    Route::get('/{id}/download-po-customer-file', [PurchaseOrderController::class, 'downloadPoCustomerFile']);
});

Route::prefix('tender-documents')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [TenderDocumentController::class, 'index']);
    Route::post('/', [TenderDocumentController::class, 'store']);
    Route::get('/by-po/{po_id}', [TenderDocumentController::class, 'getByPO']);
    Route::get('/statistics', [TenderDocumentController::class, 'statistics']);
    Route::get('/progress/{po_id}', [TenderDocumentController::class, 'progress']);
    Route::get('/{document}', [TenderDocumentController::class, 'show']);
    Route::put('/{document}', [TenderDocumentController::class, 'update']);
    Route::delete('/{document}', [TenderDocumentController::class, 'destroy']);
    
    // Actions
    Route::post('/{document}/submit', [TenderDocumentController::class, 'submit']);
    Route::post('/{document}/verify', [TenderDocumentController::class, 'verify']);
    Route::post('/{document}/approve', [TenderDocumentController::class, 'approve']);
    Route::post('/{document}/reject', [TenderDocumentController::class, 'reject']);
    Route::get('/{document}/download', [TenderDocumentController::class, 'download']);
});



Route::prefix('agent-payments')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [AgentPaymentController::class, 'index']);
    Route::post('/', [AgentPaymentController::class, 'store']);
    Route::post('/from-supplier-po/{supplier_po_id}', [AgentPaymentController::class, 'storeFromSupplierPO']);
    Route::get('/statistics', [AgentPaymentController::class, 'statistics']);
    Route::get('/{agent_payment}', [AgentPaymentController::class, 'show']);
    Route::put('/{agent_payment}', [AgentPaymentController::class, 'update']);
    Route::delete('/{agent_payment}', [AgentPaymentController::class, 'destroy']);

    // Payment & files
    Route::post('/{agent_payment}/pay', [AgentPaymentController::class, 'recordPayment']);
    Route::post('/{agent_payment}/upload-invoice', [AgentPaymentController::class, 'uploadAgentInvoice']);
});

    Route::prefix('activity-types')->group(function () {
    Route::get('/', [ActivityTypeController::class, 'index']);
    Route::post('/', [ActivityTypeController::class, 'store']);
    Route::get('/{id}', [ActivityTypeController::class, 'show']);
    Route::put('/{id}', [ActivityTypeController::class, 'update']);
    Route::delete('/{id}', [ActivityTypeController::class, 'destroy']);
});
    

    // ==================== PURCHASE ORDERS (CUSTOMER) ====================
Route::prefix('purchase-orders')->group(function () {
    Route::get('/', [PurchaseOrderController::class, 'index']);
    Route::post('/', [PurchaseOrderController::class, 'store']);
    Route::get('/{id}', [PurchaseOrderController::class, 'show']);
    Route::put('/{id}', [PurchaseOrderController::class, 'update']);
    Route::delete('/{id}', [PurchaseOrderController::class, 'destroy']);
    Route::post('/{id}/issue', [PurchaseOrderController::class, 'issue']);
    Route::patch('/{id}/status', [PurchaseOrderController::class, 'updateStatus']);
    Route::get('/{id}/pdf', [PurchaseOrderController::class, 'generatePdf']);
    Route::get('/{id}/po-file', [PurchaseOrderController::class, 'downloadPoFile']);
    
    // ✅ TAMBAHAN: Upload & Download PO Customer
    Route::post('/{id}/upload-po-customer', [PurchaseOrderController::class, 'uploadPoCustomerFile']);
    Route::get('/{id}/po-customer-file', [PurchaseOrderController::class, 'downloadPoCustomerFile']);
});
 // ==================== SUPPLIER DELIVERY NOTES (Master Data) ====================
Route::prefix('supplier-delivery-notes')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [SupplierDeliveryNoteController::class, 'index']);
    Route::post('/', [SupplierDeliveryNoteController::class, 'store']);
    Route::get('/pending', [SupplierDeliveryNoteController::class, 'getPending']);
    Route::get('/{id}', [SupplierDeliveryNoteController::class, 'show']);
    Route::put('/{id}', [SupplierDeliveryNoteController::class, 'update']);
    Route::delete('/{id}', [SupplierDeliveryNoteController::class, 'destroy']);
    
    // Actions
    Route::post('/{id}/receive', [SupplierDeliveryNoteController::class, 'receiveGoods']);
    Route::post('/{id}/cancel', [SupplierDeliveryNoteController::class, 'cancel']);
    Route::get('/{id}/download', [SupplierDeliveryNoteController::class, 'downloadFile']);
});

    // ==================== PROFORMA INVOICE ====================
    Route::prefix('proforma-invoices')->group(function () {
        Route::get('/', [ProformaInvoiceController::class, 'index']);
        Route::post('/', [ProformaInvoiceController::class, 'store']);
        Route::get('/{id}', [ProformaInvoiceController::class, 'show']);
        Route::put('/{id}', [ProformaInvoiceController::class, 'update']);
        Route::delete('/{id}', [ProformaInvoiceController::class, 'destroy']);
        
        // Workflow actions
        Route::post('/{id}/issue', [ProformaInvoiceController::class, 'issue']);
        Route::patch('/{id}/status', [ProformaInvoiceController::class, 'updateStatus']);
        
        // ✅ NEW: Convert to Invoice
        Route::post('/{id}/convert', [ProformaInvoiceController::class, 'convertToInvoice']);
        
        // PDF
        Route::get('/{id}/pdf', [ProformaInvoicePDFController::class, 'generate']);
        Route::get('/{id}/pdf/download', [ProformaInvoicePDFController::class, 'download']);
    });

    // ==================== INVOICES ====================
// Invoice Routes
// Invoice Routes
Route::prefix('invoices')->group(function () {
    Route::get('/', [InvoiceController::class, 'index']);
    Route::post('/', [InvoiceController::class, 'store']);
    Route::get('/generate-number', [InvoiceController::class, 'generateNumber']);
    Route::get('/{id}', [InvoiceController::class, 'show']);
    Route::put('/{id}', [InvoiceController::class, 'update']);
    Route::delete('/{id}', [InvoiceController::class, 'destroy']);
    
    // ✅ PDF Download
    Route::get('/{id}/pdf', [InvoiceController::class, 'downloadPdf']);
    
    // ✅ Payment
    Route::post('/{id}/payments', [InvoiceController::class, 'createPayment']);
    
    // ✅ Delivery Note - Support BOTH singular & plural
    Route::post('/{id}/delivery-note', [InvoiceController::class, 'createDeliveryNote']);
    Route::post('/{id}/delivery-notes', [InvoiceController::class, 'createDeliveryNote']);
});

Route::prefix('tax-invoices')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [TaxInvoiceController::class, 'index']);
    Route::post('/', [TaxInvoiceController::class, 'store']);
    Route::get('/statistics', [TaxInvoiceController::class, 'statistics']);
    Route::get('/{tax_invoice}', [TaxInvoiceController::class, 'show']);
    Route::put('/{tax_invoice}', [TaxInvoiceController::class, 'update']);
    Route::delete('/{tax_invoice}', [TaxInvoiceController::class, 'destroy']);
    
    // Actions
    Route::post('/{tax_invoice}/submit', [TaxInvoiceController::class, 'submit']);
    Route::post('/{tax_invoice}/approve', [TaxInvoiceController::class, 'approve']);
    Route::post('/{tax_invoice}/reject', [TaxInvoiceController::class, 'reject']);
    Route::get('/{tax_invoice}/download', [TaxInvoiceController::class, 'download']);
});



   // ==================== PAYMENTS ====================
    Route::prefix('payments')->group(function () {
        Route::get('/', [PaymentController::class, 'index']);
        Route::post('/', [PaymentController::class, 'store']);
        Route::get('/summary', [PaymentController::class, 'summary']);
        Route::get('/{id}', [PaymentController::class, 'show']);
        Route::put('/{id}', [PaymentController::class, 'update']);
        Route::delete('/{id}', [PaymentController::class, 'destroy']);
        
        // ✅ NEW: Approve & Cancel
        Route::post('/{id}/approve', [PaymentController::class, 'approve']);
        Route::post('/{id}/cancel', [PaymentController::class, 'cancel']);
        
        // ✅ Generate Receipt
        Route::post('/{id}/generate-receipt', [PaymentController::class, 'generateReceipt']);
    });

    Route::prefix('stock-returns')->group(function () {
    // CRUD
    Route::get('/', [StockReturnController::class, 'index']);
    Route::post('/', [StockReturnController::class, 'store']);
    Route::get('/{id}', [StockReturnController::class, 'show']);
    Route::put('/{id}', [StockReturnController::class, 'update']);
    Route::delete('/{id}', [StockReturnController::class, 'destroy']);
    
    // Workflow
    Route::post('/{id}/submit', [StockReturnController::class, 'submit']);
    Route::post('/{id}/approve', [StockReturnController::class, 'approve']);
    Route::post('/{id}/reject', [StockReturnController::class, 'reject']);
    Route::post('/{id}/process', [StockReturnController::class, 'process']);
    Route::post('/{id}/cancel', [StockReturnController::class, 'cancel']);
    
    // Create from existing records
    Route::post('/from-delivery-note/{delivery_note_id}', [StockReturnController::class, 'createFromDeliveryNote']);
    Route::post('/from-stock-in/{stock_in_id}', [StockReturnController::class, 'createFromStockIn']);
    
    // Proof file management
    Route::post('/{id}/upload-proof', [StockReturnController::class, 'uploadProof']);
    Route::get('/{id}/download-proof', [StockReturnController::class, 'downloadProof']);
    Route::delete('/{id}/proof', [StockReturnController::class, 'deleteProof']);
    
    // Query helpers
    Route::get('/by-delivery-note/{delivery_note_id}', [StockReturnController::class, 'getByDeliveryNote']);
    Route::get('/by-stock-out/{stock_out_id}', [StockReturnController::class, 'getByStockOut']);
    Route::get('/by-stock-in/{stock_in_id}', [StockReturnController::class, 'getByStockIn']);
    
    // Reports & Utilities
    Route::get('/summary', [StockReturnController::class, 'summary']);
    Route::get('/generate-number', [StockReturnController::class, 'generateNumber']);
});


    // ==================== RECEIPTS ====================
Route::prefix('receipts')->group(function () {
    Route::get('/', [ReceiptController::class, 'index']);
    Route::post('/', [ReceiptController::class, 'store']);
    Route::get('/{id}', [ReceiptController::class, 'show']);
    Route::put('/{id}', [ReceiptController::class, 'update']);
    Route::delete('/{id}', [ReceiptController::class, 'destroy']);
    
    // ✅ ADD: Generate from payment
    Route::post('/generate/{paymentId}', [ReceiptController::class, 'generateFromPayment']);
    
    // ✅ ADD: Download PDF
    Route::get('/{id}/pdf', [ReceiptController::class, 'downloadPdf']);
});
    // ==================== DELIVERY NOTES ====================
     Route::prefix('delivery-notes')->group(function () {
        Route::get('/', [DeliveryNoteController::class, 'index']);
        Route::post('/', [DeliveryNoteController::class, 'store']);
        Route::get('/{id}', [DeliveryNoteController::class, 'show']);
        Route::put('/{id}', [DeliveryNoteController::class, 'update']);
        Route::delete('/{id}', [DeliveryNoteController::class, 'destroy']);
        Route::post('/{id}/issue', [DeliveryNoteController::class, 'issue']);
        Route::get('/{id}/pdf', [DeliveryNoteController::class, 'generatePDF']);
        Route::post('/from-po/{po_id}', [DeliveryNoteController::class, 'createFromPurchaseOrder']);
        Route::get('/by-number/{delivery_note_number}', [DeliveryNoteController::class, 'getByNumber']);
        Route::get('/by-po/{po_id}', [DeliveryNoteController::class, 'getByPurchaseOrder']);
        Route::get('/by-quotation/{quotation_id}', [DeliveryNoteController::class, 'getByQuotation']);
        Route::get('/generate-number/{company_id}', [DeliveryNoteController::class, 'generateNumber']);
    });
    

    // ==================== STOCK MOVEMENTS ====================
    Route::prefix('stock-movements')->group(function () {
        Route::get('/', [StockMovementController::class, 'index']);
        Route::post('/', [StockMovementController::class, 'store']);
        Route::get('/summary', [StockMovementController::class, 'summary']);
        Route::get('/batch/{batchId}', [StockMovementController::class, 'historyByBatch']);
        Route::get('/{id}', [StockMovementController::class, 'show']);
        Route::put('/{id}', [StockMovementController::class, 'update']);
        Route::delete('/{id}', [StockMovementController::class, 'destroy']);
    });

    // ==================== SUPPLIERS ====================
    Route::prefix('suppliers')->group(function () {
        Route::get('/', [SupplierController::class, 'index']);
        Route::post('/', [SupplierController::class, 'store']);
        Route::get('/{id}', [SupplierController::class, 'show']);
        Route::match(['put', 'patch'], '/{id}', [SupplierController::class, 'update']);
        Route::delete('/{id}', [SupplierController::class, 'destroy']);
    });

    // ==================== SUPPLIER PO ====================
    Route::prefix('supplier-po')->group(function () {
        Route::get('/', [SupplierPoController::class, 'index']);
        Route::post('/', [SupplierPoController::class, 'store']);
        Route::get('/{id}', [SupplierPoController::class, 'show']);
        Route::match(['put', 'patch'], '/{id}', [SupplierPoController::class, 'update']);
        Route::patch('/{id}/status', [SupplierPoController::class, 'updateStatus']);
        Route::delete('/{id}', [SupplierPoController::class, 'destroy']);
    });

    // ==================== STOCK BATCHES ====================
    Route::prefix('stock-batches')->group(function () {
        Route::get('/', [StockBatchController::class, 'index']);
        Route::post('/', [StockBatchController::class, 'store']);
        Route::get('/real-time', [StockBatchController::class, 'realTimeStock']);
        Route::get('/fifo-recommendation', [StockBatchController::class, 'fifoRecommendation']);
        Route::get('/by-product', [StockBatchController::class, 'byProduct']);
        Route::get('/expiring', [StockBatchController::class, 'expiring']);
        Route::get('/expired', [StockBatchController::class, 'expired']);
        Route::get('/{id}', [StockBatchController::class, 'show']);
        Route::get('/{id}/history', [StockBatchController::class, 'batchHistory']);
        Route::match(['put', 'patch'], '/{id}', [StockBatchController::class, 'update']);
        Route::delete('/{id}', [StockBatchController::class, 'destroy']);
    });

    // ==================== STOCK IN ====================
    Route::prefix('stock-in')->group(function () {
        Route::get('/', [StockInController::class, 'index']);
        Route::post('/', [StockInController::class, 'store']);
        Route::get('/summary', [StockInController::class, 'summary']);
        Route::get('/{id}', [StockInController::class, 'show']);
        Route::match(['put', 'patch'], '/{id}', [StockInController::class, 'update']);
        Route::delete('/{id}', [StockInController::class, 'destroy']);
        Route::get('/{id}/download-delivery-note', [StockInController::class, 'downloadDeliveryNote']);
        Route::delete('/{id}/delivery-note', [StockInController::class, 'deleteDeliveryNoteFile']);
    });

    // ==================== STOCK OUT ====================
    Route::prefix('stock-out')->group(function () {
        Route::get('/', [StockOutController::class, 'index']);
        Route::post('/', [StockOutController::class, 'store']);
        Route::get('/summary', [StockOutController::class, 'summary']);
        Route::get('/report', [StockOutController::class, 'report']);
        Route::get('/batches/{productId}', [StockOutController::class, 'getBatchesByProduct']);
        Route::get('/by-delivery/{deliveryNoteId}', [StockOutController::class, 'getByDeliveryNote']);
        Route::get('/{id}', [StockOutController::class, 'show']);
        Route::match(['put', 'patch'], '/{id}', [StockOutController::class, 'update']);
        Route::delete('/{id}', [StockOutController::class, 'destroy']);
        Route::post('/bulk-from-delivery', [StockOutController::class, 'bulkFromDeliveryNote']);
        Route::post('/{id}/mark-received', [StockOutController::class, 'markAsReceived']);
        Route::post('/{id}/upload-proof', [StockOutController::class, 'uploadProof']);
    });

    // ==================== STOCK OPENING ====================
    Route::prefix('stock-opening')->group(function () {
        Route::get('/', [StockOpeningController::class, 'index']);
        Route::post('/', [StockOpeningController::class, 'store']);
        Route::post('/bulk', [StockOpeningController::class, 'bulkStore']);
        Route::post('/import', [StockOpeningController::class, 'importFromExcel']);
        Route::get('/template', [StockOpeningController::class, 'downloadTemplate']);
        Route::get('/by-period', [StockOpeningController::class, 'byPeriod']);
        Route::post('/copy-period', [StockOpeningController::class, 'copyToNextPeriod']);
        Route::get('/{id}', [StockOpeningController::class, 'show']);
        Route::match(['put', 'patch'], '/{id}', [StockOpeningController::class, 'update']);
        Route::delete('/{id}', [StockOpeningController::class, 'destroy']);
    });

    // ==================== ENDING STOCK ====================
    Route::prefix('ending-stock')->group(function () {
        Route::get('/', [EndingStockController::class, 'index']);
        Route::post('/recalculate', [EndingStockController::class, 'recalculate']);
    });

    // ==================== ROLES & USERS ====================
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index']);
        Route::post('/', [RoleController::class, 'store']);
        Route::get('/dropdown', [RoleController::class, 'dropdown']);
        Route::get('/statistics', [RoleController::class, 'statistics']);
        Route::get('/{id}', [RoleController::class, 'show']);
        Route::get('/{id}/users', [RoleController::class, 'users']);
        Route::match(['put', 'patch'], '/{id}', [RoleController::class, 'update']);
        Route::delete('/{id}', [RoleController::class, 'destroy']);
    });

    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/dropdown', [UserController::class, 'dropdown']);
        Route::get('/me', [UserController::class, 'me']);
        Route::put('/me', [UserController::class, 'updateProfile']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::match(['put', 'patch'], '/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
        Route::post('/{id}/activate', [UserController::class, 'activate']);
    });

    // ==================== DOCUMENT ATTACHMENTS ====================
    Route::prefix('document-attachments')->group(function () {
        Route::get('/', [DocumentAttachmentController::class, 'index']);
        Route::post('/', [DocumentAttachmentController::class, 'store']);
        Route::get('/{documentAttachment}', [DocumentAttachmentController::class, 'show']);
        Route::get('/{documentAttachment}/download', [DocumentAttachmentController::class, 'download']);
        Route::delete('/{documentAttachment}', [DocumentAttachmentController::class, 'destroy']);
        
        // By document type
        Route::get('/{documenttype}/{referenceid}', [DocumentAttachmentController::class, 'byDocument'])
            ->where(['documenttype' => 'quotation|po|invoice|proforma_invoice|deliverynote|payment', 'referenceid' => '[0-9]+']);
        Route::delete('/{documenttype}/{referenceid}', [DocumentAttachmentController::class, 'bulkDelete'])
            ->where(['documenttype' => 'quotation|po|invoice|proforma_invoice|deliverynote|payment', 'referenceid' => '[0-9]+']);
    });

    // ==================== OCR ====================
    Route::post('/ocr/extract-delivery-note', [OcrController::class, 'extractDeliveryNote']);

    // ==================== HEALTH CHECK ====================
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now(),
            'version' => '2.1.0',
            'features' => [
                'proforma_invoice' => 'enabled',
                'invoice_payment' => 'enabled',
                'delivery_note' => 'enabled',
            ]
        ]);
    });
});
