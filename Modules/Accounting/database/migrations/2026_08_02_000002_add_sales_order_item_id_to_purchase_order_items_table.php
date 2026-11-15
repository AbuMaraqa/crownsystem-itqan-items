<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_order_items';

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'sales_order_item_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $column = $table->unsignedBigInteger('sales_order_item_id')->nullable();

            if (Schema::hasColumn($tableName, 'purchase_order_id')) {
                $column->after('purchase_order_id');
            }

            $table->index('sales_order_item_id', 'acct_po_items_sales_order_item_idx');

            $table->foreign('sales_order_item_id', 'acct_po_items_sales_order_item_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'sales_order_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_order_items';

        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'sales_order_item_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropForeign('acct_po_items_sales_order_item_fk');
            $table->dropColumn('sales_order_item_id');
        });
    }
};
