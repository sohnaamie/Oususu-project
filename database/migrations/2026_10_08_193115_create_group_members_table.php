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
        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('role')->default('member');
            $table->unsignedInteger('turn_order')->nullable();
            $table->string('status')->default('active');
            $table->unsignedInteger('number_of_shares')->default(1);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            // stops the user from joining the same group multiple times
            $table->unique(['group_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_members');
    }
};
