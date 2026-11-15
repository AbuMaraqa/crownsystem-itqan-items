<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('accounting.table_prefix').'consolidated_purchase_order_clearances', function (Blueprint $table) {
            $table->decimal('total_cost', 18, 4)->nullable()->after('clearance_company_id');
        });
    }

    public function down(): void
    {
        Schema::table(config('accounting.table_prefix').'consolidated_purchase_order_clearances', function (Blueprint $table) {
            $table->dropColumn('total_cost');
        });
    }
};
