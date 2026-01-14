<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop existing users table if exists
        Schema::dropIfExists('users');
        
        Schema::create('users', function (Blueprint $table) {
            $table->id('user_id');
            $table->string('username', 100)->unique();
            $table->string('email', 255)->unique();
            $table->string('password');
            $table->string('full_name', 255);
            $table->unsignedBigInteger('role_id');
            $table->string('phone', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login')->nullable();
            $table->timestamps();

            $table->foreign('role_id')->references('role_id')->on('roles');
            
            $table->index('username');
            $table->index('email');
            $table->index('role_id');
            $table->index('is_active');
        });

        // Insert default admin user
        DB::table('users')->insert([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('admin123'),
            'full_name' => 'System Administrator',
            'role_id' => 1, // Super Admin
            'phone' => '08123456789',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
