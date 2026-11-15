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

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference_no')->unique('acct_purchase_invoice_reference_unique');
            $table->unsignedTinyInteger('status')->default(PurchaseInvoiceStatus::DRAFT->value());
            $table->date('invoice_date');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('tax_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'taxes')
                ->nullOnDelete();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('sub_total', 18, 4)->default(0);
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

            $table->index(['invoice_date', 'currency_id'], 'acct_purchase_invoice_date_currency_idx');
            $table->index(['status', 'invoice_date'], 'acct_purchase_invoice_status_date_idx');
            $table->index(['client_id', 'invoice_date'], 'acct_purchase_invoice_client_date_idx');
            $table->index('journal_entry_id', 'acct_purchase_invoice_journal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'purchase_invoices');
    }
};
