<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('accounting.table_prefix').'journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')
                ->constrained(config('accounting.table_prefix').'journal_entries')
                ->cascadeOnDelete();
            $table->foreignId('account_id')
                ->constrained(config('accounting.table_prefix').'accounts')
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained(config('branch.table_prefix').'branches')
                ->nullOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->decimal('debit_base', 18, 4)->default(0);
            $table->decimal('credit_base', 18, 4)->default(0);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['account_id', 'branch_id'], 'acct_jl_account_branch_idx');
            $table->index(['journal_entry_id', 'account_id'], 'acct_jl_entry_account_idx');
            $table->index(['branch_id', 'account_id'], 'acct_jl_branch_account_idx');
            $table->index(['currency_id'], 'acct_jl_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'journal_lines');
    }
};
