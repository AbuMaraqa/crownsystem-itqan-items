<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اصناف الطلبية المتأثرة باعادة التخمين، مع ملاحظة لكل صنف.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_reappraisal_items';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();

            $table->foreignId('reappraisal_id');
            $table->foreign('reappraisal_id', 'acct_cpo_reappraisal_items_reappraisal_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_order_reappraisals')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('consolidated_purchase_order_item_id')->nullable();
            $table->foreign('consolidated_purchase_order_item_id', 'acct_cpo_reappraisal_items_item_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_order_items')
                ->cascadeOnDelete();

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(
                ['reappraisal_id', 'consolidated_purchase_order_item_id'],
                'acct_cpo_reappraisal_items_reappraisal_item_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_reappraisal_items');
    }
};
