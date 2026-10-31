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
        Schema::create('payouts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('group_id')->constrained();
        $table->unsignedInteger('period_number');
        $table->foreignId('group_member_id')->constrained();   // recipient
        $table->unsignedBigInteger('amount');                  // butut
        $table->foreignId('recorded_by')->constrained('users');
        $table->timestamp('paid_at');
        $table->timestamps();

        $table->unique(['group_id', 'period_number']);
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
