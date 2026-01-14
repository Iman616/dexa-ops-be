<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id('batch_id');
            $table->string('batch_number', 100)->unique();
            $table->foreignId('product_id')->constrained('products', 'product_id')->onDelete('restrict');
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();
            
            $table->index('batch_number', 'idx_batch_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
