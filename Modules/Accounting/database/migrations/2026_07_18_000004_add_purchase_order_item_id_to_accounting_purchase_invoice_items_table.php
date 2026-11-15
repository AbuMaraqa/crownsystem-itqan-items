<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_invoice_items';

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'purchase_order_item_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $column = $table->unsignedBigInteger('purchase_order_item_id')->nullable();

            if (Schema::hasColumn($tableName, 'purchase_invoice_id')) {
                $column->after('purchase_invoice_id');
            }

            $table->index('purchase_order_item_id', 'acct_purchase_invoice_item_order_item_idx');

            $table->foreign('purchase_order_item_id', 'acct_pi_items_order_item_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'purchase_order_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_invoice_items';

        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'purchase_order_item_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropForeign('acct_pi_items_order_item_fk');
            $table->dropColumn('purchase_order_item_id');
        });
    }
};
