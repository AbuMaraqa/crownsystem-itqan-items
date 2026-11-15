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
            $table->decimal('length', 10, 4)->nullable()->after('weight_per_meter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(config('items.table_prefix').'products', function (Blueprint $table) {
            $table->dropColumn('length');
        });
    }
};
