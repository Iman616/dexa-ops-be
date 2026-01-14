<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id('product_id');
            $table->string('product_code', 100)->unique();
            $table->string('product_name', 255);
            $table->string('category', 100)->nullable();
            $table->string('unit', 50)->default('pcs');
            $table->boolean('is_precursor')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('product_code', 'idx_product_code');
            $table->index('product_name', 'idx_product_name');
            $table->index('is_precursor', 'idx_precursor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
