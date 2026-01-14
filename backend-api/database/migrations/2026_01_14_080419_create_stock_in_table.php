<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_in', function (Blueprint $table) {
            $table->id('stock_in_id');
            $table->foreignId('supplier_po_id')->nullable()->constrained('supplier_po', 'supplier_po_id')->onDelete('set null');
            $table->foreignId('product_id')->constrained('products', 'product_id')->onDelete('restrict');
            $table->foreignId('batch_id')->constrained('stock_batches', 'batch_id')->onDelete('restrict');
            $table->integer('quantity');
            $table->decimal('purchase_price', 15, 2);
            $table->timestamp('received_date')->useCurrent();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamps();
            
            $table->index('product_id', 'idx_product');
            $table->index('batch_id', 'idx_batch');
            $table->index('received_date', 'idx_received_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_in');
    }
};
