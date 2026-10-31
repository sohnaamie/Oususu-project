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
        // make_total_payments_nullable_on_groups, inside up()
     Schema::table('groups', function (Blueprint $table) {
    $table->unsignedInteger('total_payments')->nullable()->change();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedInteger('total_payments')->nullable(false)->change();
        });
    }
};
