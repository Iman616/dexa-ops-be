<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_po', function (Blueprint $table) {
            $table->id('supplier_po_id');
            $table->foreignId('supplier_id')->constrained('suppliers', 'supplier_id')->onDelete('restrict');
            $table->string('po_number', 50)->unique();
            $table->date('po_date');
            $table->enum('status', ['draft', 'sent', 'approved', 'received', 'cancelled'])->default('draft');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->timestamps();
            
            $table->index('po_number', 'idx_po_number');
            $table->index('status', 'idx_status');
            $table->index('supplier_id', 'idx_supplier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_po');
    }
};
