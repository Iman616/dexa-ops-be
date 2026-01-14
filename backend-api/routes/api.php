<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierPoController;
use App\Http\Controllers\Api\StockBatchController;
use App\Http\Controllers\Api\StockInController;
use App\Http\Controllers\Api\StockOutController;
use App\Http\Controllers\Api\StockOpeningController;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES (NO AUTH)
|--------------------------------------------------------------------------
*/

// Test API
Route::get('/test', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'API is working!',
        'timestamp' => now()->toDateTimeString()
    ]);
});

// Auth (public)
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
    });

    // Products
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::post('/', [ProductController::class, 'store']);
        Route::get('/categories', [ProductController::class, 'categories']);
        Route::get('/{id}', [ProductController::class, 'show']);
        Route::put('/{id}', [ProductController::class, 'update']);
        Route::delete('/{id}', [ProductController::class, 'destroy']);
        Route::post('/export', [ProductController::class, 'export']);
        Route::post('/import', [ProductController::class, 'import']);
    });

    Route::prefix('suppliers')->group(function () {
    Route::get('/', [SupplierController::class, 'index']);
    Route::post('/', [SupplierController::class, 'store']);
    Route::get('/{id}', [SupplierController::class, 'show']);
    Route::put('/{id}', [SupplierController::class, 'update']);
    Route::patch('/{id}', [SupplierController::class, 'update']);
    Route::delete('/{id}', [SupplierController::class, 'destroy']);
});

// Supplier PO Routes
Route::prefix('supplier-po')->group(function () {
    Route::get('/', [SupplierPoController::class, 'index']);
    Route::post('/', [SupplierPoController::class, 'store']);
    Route::get('/{id}', [SupplierPoController::class, 'show']);
    Route::put('/{id}', [SupplierPoController::class, 'update']);
    Route::patch('/{id}', [SupplierPoController::class, 'update']);
    Route::delete('/{id}', [SupplierPoController::class, 'destroy']);
    Route::patch('/{id}/status', [SupplierPoController::class, 'updateStatus']);
});

// Stock Batch Routes
// Stock Batch Routes - Update dengan method baru
Route::prefix('stock-batches')->group(function () {
    Route::get('/', [StockBatchController::class, 'index']);
    Route::get('/real-time', [StockBatchController::class, 'realTimeStock']); 
    Route::get('/fifo-recommendation', [StockBatchController::class, 'fifoRecommendation']); 
    Route::get('/by-product', [StockBatchController::class, 'byProduct']); 
    Route::post('/', [StockBatchController::class, 'store']);
    Route::get('/expiring', [StockBatchController::class, 'expiring']);
    Route::get('/expired', [StockBatchController::class, 'expired']);
    Route::get('/{id}', [StockBatchController::class, 'show']);
    Route::get('/{id}/history', [StockBatchController::class, 'batchHistory']); 
    Route::put('/{id}', [StockBatchController::class, 'update']);
    Route::patch('/{id}', [StockBatchController::class, 'update']);
    Route::delete('/{id}', [StockBatchController::class, 'destroy']);
});



// Stock In Routes
Route::prefix('stock-in')->group(function () {
    Route::get('/', [StockInController::class, 'index']);
    Route::post('/', [StockInController::class, 'store']);
    Route::get('/summary', [StockInController::class, 'summary']);
    Route::get('/{id}', [StockInController::class, 'show']);
    Route::put('/{id}', [StockInController::class, 'update']);
    Route::patch('/{id}', [StockInController::class, 'update']);
    Route::delete('/{id}', [StockInController::class, 'destroy']);
});

// Stock Out Routes
Route::prefix('stock-out')->group(function () {
    Route::get('/', [StockOutController::class, 'index']);
    Route::post('/', [StockOutController::class, 'store']);
    Route::get('/{id}', [StockOutController::class, 'show']);
    Route::delete('/{id}', [StockOutController::class, 'destroy']);
});

// Stock Opening Routes
// Stock Opening Routes
Route::prefix('stock-opening')->group(function () {
    Route::get('/', [StockOpeningController::class, 'index']);
    Route::post('/', [StockOpeningController::class, 'store']);
    Route::post('/bulk', [StockOpeningController::class, 'bulkStore']);
    Route::post('/import', [StockOpeningController::class, 'importFromExcel']);
    Route::get('/template', [StockOpeningController::class, 'downloadTemplate']);
    Route::get('/by-period', [StockOpeningController::class, 'byPeriod']);
    Route::post('/copy-period', [StockOpeningController::class, 'copyToNextPeriod']);
    Route::get('/{id}', [StockOpeningController::class, 'show']);
    Route::put('/{id}', [StockOpeningController::class, 'update']);
    Route::patch('/{id}', [StockOpeningController::class, 'update']);
    Route::delete('/{id}', [StockOpeningController::class, 'destroy']);
});


});
