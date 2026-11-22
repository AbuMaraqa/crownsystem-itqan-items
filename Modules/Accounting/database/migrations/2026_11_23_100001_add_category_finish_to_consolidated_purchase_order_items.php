<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سطر مجموعة السعر في الطلبية التجميعية: يُسحب من طلبية شراء سطرها (التصنيف × اللون) بلا صنف،
 * فيحمل مجموعته مثلها، وقطعه ألوان مقاطعه. يُمنع حذف مجموعة سعر عليها سطور.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('accounting.table_prefix').'consolidated_purchase_order_items';

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'category_finish_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreignId('category_finish_id')->nullable()->after('product_id')
                ->constrained(config('items.table_prefix').'category_finishes', 'id', 'acct_cpo_items_cat_fin_fk')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $table = config('accounting.table_prefix').'consolidated_purchase_order_items';

        if (Schema::hasColumn($table, 'category_finish_id')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign('acct_cpo_items_cat_fin_fk');
                $blueprint->dropColumn('category_finish_id');
            });
        }
    }
};
