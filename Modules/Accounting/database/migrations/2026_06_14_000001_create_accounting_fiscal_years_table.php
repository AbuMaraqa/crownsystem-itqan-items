<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\AccountingPeriodStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('accounting.table_prefix') . 'fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->unsignedTinyInteger('status')->default(AccountingPeriodStatus::OPEN->value());
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['starts_at', 'ends_at'], 'acct_fy_dates_unique');
            $table->index(['status', 'is_default'], 'acct_fy_status_default_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix') . 'fiscal_years');
    }
};
