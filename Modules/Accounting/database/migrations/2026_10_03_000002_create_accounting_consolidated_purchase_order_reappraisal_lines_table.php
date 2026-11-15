<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قيم البنود داخل اعادة التخمين: كل صف بند مختار من القائمة وقيمته.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_reappraisal_lines';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();

            $table->foreignId('reappraisal_id');
            $table->foreign('reappraisal_id', 'acct_cpo_reappraisal_lines_reappraisal_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_order_reappraisals')
                ->cascadeOnDelete();

            // البند يُحذف حذفاً ناعماً فقط، فالقيد يمنع ضياع مرجع القيم المحفوظة.
            $table->foreignId('reappraisal_element_id');
            $table->foreign('reappraisal_element_id', 'acct_cpo_reappraisal_lines_element_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'reappraisal_elements')
                ->restrictOnDelete();

            $table->decimal('amount', 18, 4)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('reappraisal_id', 'acct_cpo_reappraisal_lines_reappraisal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_reappraisal_lines');
    }
};
