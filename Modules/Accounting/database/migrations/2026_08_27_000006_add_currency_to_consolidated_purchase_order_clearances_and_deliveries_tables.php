<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('accounting.table_prefix').'consolidated_purchase_order_clearances', function (Blueprint $table) {
            $table->unsignedBigInteger('currency_id')->nullable()->after('total_cost');
            $table->foreign('currency_id', 'acct_cpo_clearances_currency_fk')->references('id')->on('currencies')->nullOnDelete();
        });

        Schema::table(config('accounting.table_prefix').'consolidated_purchase_order_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('currency_id')->nullable()->after('delivery_company_id');
            $table->foreign('currency_id', 'acct_cpo_deliveries_currency_fk')->references('id')->on('currencies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table(config('accounting.table_prefix').'consolidated_purchase_order_clearances', function (Blueprint $table) {
            $table->dropForeign('acct_cpo_clearances_currency_fk');
            $table->dropColumn('currency_id');
        });

        Schema::table(config('accounting.table_prefix').'consolidated_purchase_order_deliveries', function (Blueprint $table) {
            $table->dropForeign('acct_cpo_deliveries_currency_fk');
            $table->dropColumn('currency_id');
        });
    }
};
