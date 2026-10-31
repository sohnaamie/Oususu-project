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
    {Schema::create('settlement_lines', function (Blueprint $table) {
       $table->id();
       $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
       $table->foreignId('group_member_id')->constrained();
       $table->string('outcome');                     // 'items' or 'refund'
       $table->unsignedBigInteger('amount');          // refund amount, or this member's paid amount
       $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlement_lines');
    }
};
