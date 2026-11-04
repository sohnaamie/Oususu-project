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
        Schema::table('group_members', function (Blueprint $table) {
             $table->dropColumn('turn_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('group_members', function (Blueprint $table) {
             $table->unsignedInteger('turn_order')->nullable();
        });
    }
};
