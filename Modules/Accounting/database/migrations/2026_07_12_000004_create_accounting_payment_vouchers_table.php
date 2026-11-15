<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\AccountingPaymentMethod;
use Modules\Accounting\Enums\PaymentVoucherStatus;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'payment_vouchers';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained(config('branch.table_prefix').'branches')
                ->nullOnDelete();
            $table->foreignId('account_id')
                ->constrained(config('accounting.table_prefix').'accounts')
                ->restrictOnDelete();
            $table->string('reference_no')->unique('acct_payment_voucher_reference_unique');
            $table->unsignedTinyInteger('status')->default(PaymentVoucherStatus::DRAFT->value());
            $table->date('voucher_date');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->unsignedTinyInteger('payment_method')->default(AccountingPaymentMethod::CASH->value());
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('cheque_number')->nullable();
            $table->string('cheque_bank_name')->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('cheque_image')->nullable();
            $table->foreignId('journal_entry_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'journal_entries')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['voucher_date', 'currency_id'], 'acct_payment_voucher_date_currency_idx');
            $table->index(['status', 'voucher_date'], 'acct_payment_voucher_status_date_idx');
            $table->index(['client_id', 'voucher_date'], 'acct_payment_voucher_client_date_idx');
            $table->index('journal_entry_id', 'acct_payment_voucher_journal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'payment_vouchers');
    }
};
