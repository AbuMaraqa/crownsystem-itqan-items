<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\FinancialEntryStatus;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_bank_credits';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_purchase_order_id');
            $table->foreign('consolidated_purchase_order_id', 'acct_cpo_bank_credits_order_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_orders')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('credit_type');
            $table->string('bank_name');
            $table->decimal('credit_amount', 18, 4)->default(0);
            $table->unsignedInteger('duration_days')->nullable();
            $table->date('due_date');
            $table->unsignedTinyInteger('status')->default(FinancialEntryStatus::PENDING->value());
            $table->foreignId('currency_id');
            $table->foreign('currency_id', 'acct_cpo_bank_credits_currency_fk')->references('id')->on('currencies')->restrictOnDelete();
            $table->string('attachment_path')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by', 'acct_cpo_bank_credits_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['consolidated_purchase_order_id', 'status'], 'acct_cpo_bank_credits_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_bank_credits');
    }
};
