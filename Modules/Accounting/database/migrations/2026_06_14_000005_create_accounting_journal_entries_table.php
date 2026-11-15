<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\JournalEntryStatus;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'journal_entries';

        if (Schema::hasTable($tableName)) {
            if (! Schema::hasIndex($tableName, ['fiscal_year_id', 'accounting_period_id'])) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->index(['fiscal_year_id', 'accounting_period_id'], 'acct_je_fy_period_idx');
                });
            }

            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->string('entry_number')->unique('acct_je_number_unique');
            $table->foreignId('fiscal_year_id')
                ->constrained(config('accounting.table_prefix').'fiscal_years')
                ->restrictOnDelete();
            $table->foreignId('accounting_period_id')
                ->constrained(config('accounting.table_prefix').'accounting_periods')
                ->restrictOnDelete();
            $table->date('entry_date');
            $table->unsignedTinyInteger('status')->default(JournalEntryStatus::DRAFT->value());
            $table->string('reference_no')->nullable()->index('acct_je_reference_idx');
            $table->string('source_module')->nullable()->index('acct_je_source_module_idx');
            $table->string('source_type')->nullable();
            $table->nullableMorphs('sourceable', 'acct_je_sourceable_idx');
            $table->foreignId('reversal_of_entry_id')
                ->nullable()
                ->constrained(config('accounting.table_prefix').'journal_entries')
                ->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['entry_date', 'status'], 'acct_je_date_status_idx');
            $table->index(['status', 'entry_date'], 'acct_je_status_date_idx');
            $table->index(['source_module', 'source_type'], 'acct_je_source_lookup_idx');
            $table->index(['fiscal_year_id', 'accounting_period_id'], 'acct_je_fy_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'journal_entries');
    }
};
