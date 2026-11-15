<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تخصيص الدفعات (Payment Reconciliation في ERPNext): ربط سند قبض/صرف او مرتجع بفاتورة
 * او اكثر، فيُحدَّث المتبقي على الفاتورة. المبلغ بعملة الفاتورة؛ وفرق سعر الصرف بين
 * الفاتورة والسند (بالعملة الاساسية) يُقيَّد في قيد فروقات عملة مرتبط بالتخصيص.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'payment_allocations';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('invoice_type');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->foreign('client_id', 'acct_pay_alloc_client_fk')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('currency_id');
            $table->foreign('currency_id', 'acct_pay_alloc_currency_fk')->references('id')->on('currencies')->restrictOnDelete();
            $table->date('allocation_date');
            $table->decimal('amount', 18, 4);
            $table->decimal('invoice_exchange_rate', 18, 8)->default(1);
            $table->decimal('source_exchange_rate', 18, 8)->default(1);
            $table->decimal('exchange_difference_base', 18, 4)->default(0);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id', 'acct_pay_alloc_je_fk')->references('id')->on(config('accounting.table_prefix').'journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by', 'acct_pay_alloc_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'acct_pay_alloc_source_idx');
            $table->index(['invoice_type', 'invoice_id'], 'acct_pay_alloc_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'payment_allocations');
    }
};
