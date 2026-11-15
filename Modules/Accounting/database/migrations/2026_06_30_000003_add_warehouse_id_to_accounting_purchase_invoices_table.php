<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix') . 'purchase_invoices';

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'warehouse_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $column = $table->foreignId('warehouse_id')->nullable();

            if (Schema::hasColumn($tableName, 'branch_id')) {
                $column->after('branch_id');
            }

            $column
                ->constrained(config('accounting.table_prefix') . 'warehouses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $tableName = config('accounting.table_prefix') . 'purchase_invoices';

        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'warehouse_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
