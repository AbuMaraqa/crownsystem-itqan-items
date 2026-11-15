<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قيد اقفال السنة (Period Closing Voucher): يصفّر حسابات الايرادات والمصروفات في آخر يوم
 * من السنة المالية وينقل صافي الربح/الخسارة الى «الأرباح المحتجزة»، فتبدأ السنة التالية
 * بقائمة دخل نظيفة وتبقى الميزانية متوازنة.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');
        $tableName = $prefix.'period_closing_vouchers';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->unsignedBigInteger('fiscal_year_id');
            $table->foreign('fiscal_year_id', 'acct_pcv_fiscal_year_fk')->references('id')->on($prefix.'fiscal_years')->restrictOnDelete();
            $table->string('reference_no')->unique('acct_pcv_reference_unique');
            $table->unsignedTinyInteger('status')->default(1);
            $table->date('closing_date');
            $table->unsignedBigInteger('retained_earnings_account_id');
            $table->foreign('retained_earnings_account_id', 'acct_pcv_retained_account_fk')->references('id')->on($prefix.'accounts')->restrictOnDelete();
            $table->decimal('net_profit_base', 18, 4)->default(0);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id', 'acct_pcv_je_fk')->references('id')->on($prefix.'journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by', 'acct_pcv_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->foreign('cancelled_by', 'acct_pcv_cancelled_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['fiscal_year_id', 'status'], 'acct_pcv_year_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'period_closing_vouchers');
    }
};
