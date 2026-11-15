<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\SalesInvoiceStatus;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'sales_invoices';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'sales_orders')
                ->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained(config('branch.table_prefix').'branches')
                ->nullOnDelete();
            $table->foreignId('warehouse_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'warehouses')
                ->nullOnDelete();
            $table->string('reference_no')->unique('acct_sales_invoice_reference_unique');
            $table->unsignedTinyInteger('status')->default(SalesInvoiceStatus::DRAFT->value());
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('tax_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'taxes')
                ->nullOnDelete();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('sub_total', 18, 4)->default(0);
            $table->decimal('items_discount', 18, 4)->default(0);
            $table->decimal('invoice_discount', 18, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->foreignId('journal_entry_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'journal_entries')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['invoice_date', 'currency_id'], 'acct_sales_invoice_date_currency_idx');
            $table->index(['status', 'invoice_date'], 'acct_sales_invoice_status_date_idx');
            $table->index(['client_id', 'invoice_date'], 'acct_sales_invoice_client_date_idx');
            $table->index('sales_order_id', 'acct_sales_invoice_order_idx');
            $table->index('warehouse_id', 'acct_sales_invoice_warehouse_idx');
            $table->index('journal_entry_id', 'acct_sales_invoice_journal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'sales_invoices');
    }
};
