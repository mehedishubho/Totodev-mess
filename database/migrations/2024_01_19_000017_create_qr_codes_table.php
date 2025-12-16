<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('mess_id')->constrained()->onDelete('cascade');
            $table->longText('qr_data'); // JSON data encoded in QR
            $table->string('qr_token', 64)->unique(); // Unique token for QR URL
            $table->datetime('expires_at');
            $table->boolean('is_active')->default(true);
            $table->integer('usage_count')->default(0);
            $table->integer('max_usage')->default(1);
            $table->string('purpose')->default('general'); // meal_attendance, mess_access, guest_access
            $table->json('metadata')->nullable(); // Additional data
            $table->timestamps();

            // Indexes for performance
            $table->index(['user_id', 'is_active']);
            $table->index(['mess_id', 'is_active']);
            $table->index(['qr_token']);
            $table->index(['expires_at']);
            $table->index(['purpose']);
            $table->index(['is_active', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
