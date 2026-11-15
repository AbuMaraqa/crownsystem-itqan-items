<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('accounting.table_prefix').'account_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')
                ->constrained(config('accounting.table_prefix').'accounts')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained(config('branch.table_prefix').'branches')
                ->nullOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')
                ->constrained(config('accounting.table_prefix').'fiscal_years')
                ->cascadeOnDelete();
            $table->foreignId('accounting_period_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'accounting_periods')
                ->cascadeOnDelete();
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->decimal('debit_base', 18, 4)->default(0);
            $table->decimal('credit_base', 18, 4)->default(0);
            $table->decimal('balance_base', 18, 4)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(
                ['account_id', 'branch_id', 'currency_id', 'fiscal_year_id', 'accounting_period_id'],
                'acct_balances_unique'
            );
            $table->index(['fiscal_year_id', 'accounting_period_id', 'account_id'], 'acct_bal_period_account_idx');
            $table->index(['branch_id', 'account_id'], 'acct_bal_branch_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'account_balances');
    }
};
