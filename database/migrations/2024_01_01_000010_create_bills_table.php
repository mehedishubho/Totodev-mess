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
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->tinyInteger('month');
            $table->smallInteger('year');
            $table->integer('total_meals')->default(0);
            $table->decimal('meal_cost', 10, 2)->default(0);
            $table->decimal('additional_cost', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->decimal('due_amount', 10, 2)->default(0);
            $table->enum('status', ['generated', 'partially_paid', 'fully_paid'])->default('generated');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'month', 'year']);
            $table->index('member_id');
            $table->index('month');
            $table->index('year');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
