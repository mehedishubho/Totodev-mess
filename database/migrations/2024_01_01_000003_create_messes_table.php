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
        Schema::create('messes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('logo')->nullable();
            $table->decimal('meal_rate_breakfast', 8, 2)->default(0);
            $table->decimal('meal_rate_lunch', 8, 2)->default(0);
            $table->decimal('meal_rate_dinner', 8, 2)->default(0);
            $table->enum('payment_cycle', ['weekly', 'monthly', 'custom'])->default('monthly');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index('payment_cycle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messes');
    }
};
