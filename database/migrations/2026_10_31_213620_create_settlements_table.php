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
       Schema::create('settlements', function (Blueprint $table) {
          $table->id();
          $table->foreignId('group_id')->constrained();
          $table->unsignedBigInteger('pool');            // net paid by qualified members
          $table->unsignedBigInteger('spent');           // spent on items, entered by admin
          $table->unsignedInteger('qualified_count');
          $table->foreignId('recorded_by')->constrained('users');
          $table->timestamp('settled_at');
          $table->timestamps();
         $table->unique('group_id');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
