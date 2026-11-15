<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سند الاستلام/التسليم الذي يُنشأ تلقائياً عند ترحيل فاتورة الشراء/المبيعات يرتبط بها،
 * فيُلغى معها عند الغائها. السندات اليدوية تبقى بلا فاتورة.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');

        if (! Schema::hasColumn($prefix.'goods_receipts', 'purchase_invoice_id')) {
            Schema::table($prefix.'goods_receipts', function (Blueprint $table) use ($prefix) {
                $table->foreignId('purchase_invoice_id')
                    ->nullable()
                    ->after('purchase_order_id')
                    ->constrained($prefix.'purchase_invoices', 'id', 'acct_grn_purchase_invoice_fk')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn($prefix.'delivery_notes', 'sales_invoice_id')) {
            Schema::table($prefix.'delivery_notes', function (Blueprint $table) use ($prefix) {
                $table->foreignId('sales_invoice_id')
                    ->nullable()
                    ->after('sales_order_id')
                    ->constrained($prefix.'sales_invoices', 'id', 'acct_dn_sales_invoice_fk')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        if (Schema::hasColumn($prefix.'delivery_notes', 'sales_invoice_id')) {
            Schema::table($prefix.'delivery_notes', function (Blueprint $table) {
                $table->dropForeign('acct_dn_sales_invoice_fk');
                $table->dropColumn('sales_invoice_id');
            });
        }

        if (Schema::hasColumn($prefix.'goods_receipts', 'purchase_invoice_id')) {
            Schema::table($prefix.'goods_receipts', function (Blueprint $table) {
                $table->dropForeign('acct_grn_purchase_invoice_fk');
                $table->dropColumn('purchase_invoice_id');
            });
        }
    }
};
