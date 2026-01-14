<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opening', function (Blueprint $table) {
            $table->id('opening_id');
            $table->foreignId('product_id')->constrained('products', 'product_id')->onDelete('restrict');
            $table->foreignId('batch_id')->constrained('stock_batches', 'batch_id')->onDelete('restrict');
            $table->integer('quantity');
            $table->decimal('value', 15, 2);
            $table->date('opening_date');
            $table->timestamps();
            
            $table->index('product_id');
            $table->index('opening_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opening');
    }
};
