<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_invoices';

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'purchase_order_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $column = $table->foreignId('purchase_order_id')->nullable();

            if (Schema::hasColumn($tableName, 'id')) {
                $column->after('id');
            }

            $column
                ->constrained(config('accounting.table_prefix').'purchase_orders')
                ->nullOnDelete();

            $table->index('purchase_order_id', 'acct_purchase_invoice_order_idx');
        });
    }

    public function down(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_invoices';

        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'purchase_order_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
