<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_out', function (Blueprint $table) {
            $table->id('stock_out_id');
            $table->foreignId('product_id')->constrained('products', 'product_id')->onDelete('restrict');
            $table->foreignId('batch_id')->constrained('stock_batches', 'batch_id')->onDelete('restrict');
            $table->string('transaction_type')->default('sales'); // sales, usage, adjustment
            $table->integer('quantity');
            $table->decimal('selling_price', 15, 2);
            $table->timestamp('out_date')->useCurrent();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamps();
            
            $table->index('product_id');
            $table->index('batch_id');
            $table->index('out_date');
            $table->index('transaction_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_out');
    }
};
