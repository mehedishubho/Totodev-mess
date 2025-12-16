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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mess_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('unit'); // kg, pcs, liters, etc.
            $table->decimal('current_stock', 10, 4)->default(0);
            $table->decimal('minimum_stock', 10, 4)->nullable();
            $table->decimal('maximum_stock', 10, 4)->nullable();
            $table->decimal('unit_cost', 10, 4)->nullable();
            $table->decimal('total_value', 12, 4)->default(0);
            $table->string('supplier')->nullable();
            $table->string('supplier_contact')->nullable();
            $table->date('last_purchase_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('storage_location')->nullable();
            $table->decimal('reorder_point', 10, 4)->nullable();
            $table->decimal('reorder_quantity', 10, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_perishable')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['mess_id', 'is_active']);
            $table->index(['mess_id', 'category']);
            $table->index(['mess_id', 'current_stock']);
            $table->index(['expiry_date']);
            $table->index(['is_perishable']);
            $table->index(['supplier']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
