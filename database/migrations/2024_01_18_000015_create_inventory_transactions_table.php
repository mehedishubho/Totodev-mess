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
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->onDelete('cascade');
            $table->enum('transaction_type', ['stock_in', 'stock_out', 'adjustment', 'waste', 'return']);
            $table->decimal('quantity', 10, 4);
            $table->decimal('unit_cost', 10, 4)->nullable();
            $table->decimal('total_cost', 12, 4)->nullable();
            $table->string('reference')->nullable(); // Invoice number, purchase order, etc.
            $table->string('reference_type')->nullable(); // bazar, purchase, adjustment, etc.
            $table->unsignedBigInteger('reference_id')->nullable(); // ID of the reference
            $table->text('notes')->nullable();
            $table->date('transaction_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['inventory_item_id', 'transaction_type']);
            $table->index(['transaction_date']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['created_by']);
            $table->index(['transaction_type', 'transaction_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
