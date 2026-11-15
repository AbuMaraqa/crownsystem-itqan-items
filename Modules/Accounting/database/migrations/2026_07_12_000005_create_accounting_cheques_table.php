<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\ChequeDirection;
use Modules\Accounting\Enums\ChequeStatus;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'cheques';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->string('chequeable_type');
            $table->unsignedBigInteger('chequeable_id');
            $table->unsignedTinyInteger('direction')->default(ChequeDirection::INCOMING->value());
            $table->string('cheque_number');
            $table->string('bank_name')->nullable();
            $table->date('cheque_date');
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained(config('branch.table_prefix').'branches')
                ->nullOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('amount', 18, 4)->default(0);
            $table->foreignId('account_id')
                ->constrained(config('accounting.table_prefix').'accounts')
                ->restrictOnDelete();
            $table->unsignedTinyInteger('status')->default(ChequeStatus::UNDER_COLLECTION->value());
            $table->foreignId('clearing_account_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'accounts')
                ->nullOnDelete();
            $table->foreignId('journal_entry_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'journal_entries')
                ->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['chequeable_type', 'chequeable_id'], 'acct_cheques_chequeable_idx');
            $table->index(['status', 'cheque_date'], 'acct_cheques_status_date_idx');
            $table->index(['client_id', 'cheque_date'], 'acct_cheques_client_date_idx');
            $table->index('direction', 'acct_cheques_direction_idx');
            $table->index('journal_entry_id', 'acct_cheques_journal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'cheques');
    }
};
