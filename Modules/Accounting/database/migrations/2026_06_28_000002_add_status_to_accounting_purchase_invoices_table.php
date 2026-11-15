<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_invoices';

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'status')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->unsignedTinyInteger('status')
                ->default(PurchaseInvoiceStatus::DRAFT->value())
                ->after('reference_no');

            $table->index(['status', 'invoice_date'], 'acct_purchase_invoice_status_date_idx');
        });
    }

    public function down(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_invoices';

        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'status')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropIndex('acct_purchase_invoice_status_date_idx');
            $table->dropColumn('status');
        });
    }
};
