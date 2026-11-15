<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\PurchaseReturnStatus;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_returns';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'purchase_invoices')
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
            $table->string('reference_no')->unique('acct_purchase_return_reference_unique');
            $table->unsignedTinyInteger('status')->default(PurchaseReturnStatus::DRAFT->value());
            $table->date('return_date');
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

            $table->index(['return_date', 'currency_id'], 'acct_purchase_return_date_currency_idx');
            $table->index(['status', 'return_date'], 'acct_purchase_return_status_date_idx');
            $table->index(['client_id', 'return_date'], 'acct_purchase_return_client_date_idx');
            $table->index('purchase_invoice_id', 'acct_purchase_return_invoice_idx');
            $table->index('journal_entry_id', 'acct_purchase_return_journal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'purchase_returns');
    }
};
