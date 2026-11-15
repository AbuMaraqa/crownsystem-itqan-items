<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('accounting.table_prefix').'purchase_order_items', function (Blueprint $table) {
            $table->decimal('length', 18, 4)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table(config('accounting.table_prefix').'purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('length');
        });
    }
};
