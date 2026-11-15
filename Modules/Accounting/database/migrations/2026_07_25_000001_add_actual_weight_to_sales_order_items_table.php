<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('accounting.table_prefix').'sales_order_items', function (Blueprint $table) {
            $table->decimal('actual_weight', 18, 4)->nullable()->after('total_weight');
        });
    }

    public function down(): void
    {
        Schema::table(config('accounting.table_prefix').'sales_order_items', function (Blueprint $table) {
            $table->dropColumn('actual_weight');
        });
    }
};
