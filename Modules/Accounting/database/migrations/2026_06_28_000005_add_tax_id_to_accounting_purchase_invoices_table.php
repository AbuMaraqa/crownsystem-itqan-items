<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_invoices';

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'tax_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->foreignId('tax_id')
                ->nullable()
                ->after('currency_id')
                ->constrained(config('accounting.table_prefix').'taxes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_invoices';

        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tax_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_id');
        });
    }
};
