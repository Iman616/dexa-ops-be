<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id('role_id');
            $table->string('role_name', 50)->unique();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->index('role_name');
        });

        // Insert default roles
        DB::table('roles')->insert([
            ['role_name' => 'Super Admin', 'description' => 'Full access to all features', 'created_at' => now()],
            ['role_name' => 'Admin', 'description' => 'Can manage orders, inventory, and customers', 'created_at' => now()],
            ['role_name' => 'Staff Gudang', 'description' => 'Can manage inventory only', 'created_at' => now()],
            ['role_name' => 'Staff Penjualan', 'description' => 'Can manage quotations and orders', 'created_at' => now()],
            ['role_name' => 'Viewer', 'description' => 'Read-only access', 'created_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
