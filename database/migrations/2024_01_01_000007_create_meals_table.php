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
        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->tinyInteger('breakfast_count')->default(0);
            $table->tinyInteger('lunch_count')->default(0);
            $table->tinyInteger('dinner_count')->default(0);
            $table->text('extra_items')->nullable();
            $table->decimal('extra_cost', 8, 2)->default(0);
            $table->enum('status', ['draft', 'locked', 'approved'])->default('draft');
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'date']);
            $table->index('member_id');
            $table->index('date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meals');
    }
};
