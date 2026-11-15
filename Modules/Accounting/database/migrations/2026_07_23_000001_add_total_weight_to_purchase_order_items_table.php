<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('accounting.table_prefix').'purchase_order_items', function (Blueprint $table) {
            $table->decimal('total_weight', 18, 4)->default(0)->after('line_total');
        });
    }

    public function down(): void
    {
        Schema::table(config('accounting.table_prefix').'purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('total_weight');
        });
    }
};
