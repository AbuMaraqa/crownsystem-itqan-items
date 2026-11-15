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
        Schema::table(config('items.table_prefix').'products', function (Blueprint $table) {
            $table->decimal('min_limit_1', 10, 2)->nullable();
            $table->decimal('min_limit_2', 10, 2)->nullable();
            $table->decimal('max_limit', 10, 2)->nullable();
            $table->decimal('site_area', 10, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(config('items.table_prefix').'products', function (Blueprint $table) {
            $table->dropColumn(['min_limit_1', 'min_limit_2', 'max_limit', 'site_area']);
        });
    }
};