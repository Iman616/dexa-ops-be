<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Tambahkan foreign key constraint untuk supplier_id di products
        Schema::table('products', function (Blueprint $table) {
            // Ubah supplier_id menjadi nullable dulu untuk data existing
            $table->bigInteger('supplier_id')->unsigned()->nullable()->change();
            
            // Tambahkan foreign key
            $table->foreign('supplier_id')
                  ->references('supplier_id')
                  ->on('suppliers')
                  ->onDelete('set null');
        });

        // Tambahkan foreign key untuk supplier_po
        Schema::table('supplier_po', function (Blueprint $table) {
            $table->foreign('supplier_id')
                  ->references('supplier_id')
                  ->on('suppliers')
                  ->onDelete('restrict');
        });

        // Tambahkan foreign key untuk stock_in
        Schema::table('stock_in', function (Blueprint $table) {
            $table->foreign('supplier_po_id')
                  ->references('supplier_po_id')
                  ->on('supplier_po')
                  ->onDelete('set null');
                  
            $table->foreign('product_id')
                  ->references('product_id')
                  ->on('products')
                  ->onDelete('restrict');
                  
            $table->foreign('batch_id')
                  ->references('batch_id')
                  ->on('stock_batches')
                  ->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
        });

        Schema::table('supplier_po', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
        });

        Schema::table('stock_in', function (Blueprint $table) {
            $table->dropForeign(['supplier_po_id']);
            $table->dropForeign(['product_id']);
            $table->dropForeign(['batch_id']);
        });
    }
};