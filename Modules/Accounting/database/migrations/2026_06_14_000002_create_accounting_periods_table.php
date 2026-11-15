<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\AccountingPeriodStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('accounting.table_prefix') . 'accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')
                ->constrained(config('accounting.table_prefix') . 'fiscal_years')
                ->cascadeOnDelete();
            $table->string('name');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->unsignedTinyInteger('status')->default(AccountingPeriodStatus::OPEN->value());
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'starts_at', 'ends_at'], 'acct_period_range_unique');
            $table->index(['fiscal_year_id', 'status'], 'acct_period_fy_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix') . 'accounting_periods');
    }
};
