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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mess_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('meal_type', ['breakfast', 'lunch', 'dinner']);
            $table->date('meal_date');
            $table->datetime('scan_time');
            $table->string('qr_code')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->datetime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('device_info')->nullable(); // Device information for scanning
            $table->string('location')->nullable(); // GPS location if available
            $table->boolean('is_manual_entry')->default(false);
            $table->foreignId('scanned_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->unique(['user_id', 'meal_date', 'meal_type'], 'attendances_unique');
            $table->index(['mess_id', 'meal_date']);
            $table->index(['mess_id', 'status']);
            $table->index(['user_id', 'meal_date']);
            $table->index(['scan_time']);
            $table->index(['status', 'approved_at']);
            $table->index(['is_manual_entry']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
