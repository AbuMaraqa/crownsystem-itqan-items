<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_reappraisals';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_purchase_order_id');
            $table->foreign('consolidated_purchase_order_id', 'acct_cpo_reappraisals_order_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_orders')
                ->cascadeOnDelete();
            $table->date('reappraisal_date');
            $table->string('authority')->nullable();
            $table->string('reference_no')->nullable();
            $table->decimal('declared_value', 18, 4)->default(0);
            $table->decimal('reappraised_value', 18, 4)->default(0);
            $table->foreignId('currency_id');
            $table->foreign('currency_id', 'acct_cpo_reappraisals_currency_fk')->references('id')->on('currencies')->restrictOnDelete();
            $table->unsignedTinyInteger('status')->default(1);
            $table->text('reason')->nullable();
            $table->string('attachment_path')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by', 'acct_cpo_reappraisals_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['consolidated_purchase_order_id', 'status'], 'acct_cpo_reappraisals_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_reappraisals');
    }
};
