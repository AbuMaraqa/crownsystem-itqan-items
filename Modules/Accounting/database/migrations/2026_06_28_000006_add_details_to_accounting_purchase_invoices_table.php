<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_invoices';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (! Schema::hasColumn($tableName, 'branch_id')) {
                $table->foreignId('branch_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained(config('branch.table_prefix').'branches')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn($tableName, 'due_date')) {
                $table->date('due_date')->nullable()->after('invoice_date');
            }

            if (! Schema::hasColumn($tableName, 'items_discount')) {
                $table->decimal('items_discount', 18, 4)->default(0)->after('sub_total');
            }

            if (! Schema::hasColumn($tableName, 'invoice_discount')) {
                $table->decimal('invoice_discount', 18, 4)->default(0)->after('items_discount');
            }
        });
    }

    public function down(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_invoices';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (Schema::hasColumn($tableName, 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }

            foreach (['due_date', 'items_discount', 'invoice_discount'] as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
