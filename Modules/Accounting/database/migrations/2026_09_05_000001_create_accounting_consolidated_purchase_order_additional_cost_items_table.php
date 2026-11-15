<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط التكلفة الاضافية باصناف الطلبية، مع ملاحظة لكل صنف.
 *
 * التكلفة الاضافية كانت رقماً على الطلبية كلها فقط: "عمولة 500" بلا اي
 * اشارة على اي الاصناف تخص. عند تدقيق التكلفة او توزيعها لاحقاً لا يوجد
 * ما يقول ان الرسوم كانت على صنفين من عشرة، ولا مكان لكتابة سبب اختيارهم.
 */
return new class extends Migration
{
    public function up(): void
    {
        $costsTable = config('accounting.table_prefix').'consolidated_purchase_order_additional_costs';
        $itemsTable = config('accounting.table_prefix').'consolidated_purchase_order_additional_cost_items';

        // ملاحظة عامة على التكلفة نفسها، غير التفاصيل: التفاصيل تصف التكلفة،
        // وهذه تشرح قرار تحميلها على الاصناف المؤشَّرة.
        if (Schema::hasTable($costsTable) && ! Schema::hasColumn($costsTable, 'notes')) {
            Schema::table($costsTable, function (Blueprint $table) {
                $table->text('notes')->nullable()->after('details');
            });
        }

        if (Schema::hasTable($itemsTable)) {
            return;
        }

        Schema::create($itemsTable, function (Blueprint $table) use ($costsTable) {
            $table->id();

            $table->foreignId('additional_cost_id');
            $table->foreign('additional_cost_id', 'acct_cpo_add_cost_items_cost_fk')
                ->references('id')
                ->on($costsTable)
                ->cascadeOnDelete();

            $table->unsignedBigInteger('consolidated_purchase_order_item_id')->nullable();
            $table->foreign('consolidated_purchase_order_item_id', 'acct_cpo_add_cost_items_item_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_order_items')
                ->cascadeOnDelete();

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(
                ['additional_cost_id', 'consolidated_purchase_order_item_id'],
                'acct_cpo_add_cost_items_cost_item_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_additional_cost_items');

        $costsTable = config('accounting.table_prefix').'consolidated_purchase_order_additional_costs';

        if (Schema::hasTable($costsTable) && Schema::hasColumn($costsTable, 'notes')) {
            Schema::table($costsTable, function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }
    }
};
