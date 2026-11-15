<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * توحيد حقول المخزون في تعريف الصنف على نهج المخزون الدائم:
 * 1. «الكمية» (stock_quantity) نسخة من رصيد دفتر المخزون، وكانت عدداً صحيحاً فتُقرَّب الكسور؛
 *    تصبح decimal(18,4) مثل الدفتر، ويُعاد حسابها من الارصدة للاصناف التي لها حركات.
 * 2. «الحد الادنى 1» كان يُستعمل ايضاً طولاً لناتج القص بالمتر. طول القطعة مكانه «الطول»،
 *    فيُنقل اليه في اصناف ناتج القص التي ليس لها طول، ويبقى الحد الادنى 1 حداً لاعادة الطلب.
 * 3. حدود المخزون من النظام القديم (ItemMax/ItemMin1/ItemMin2 في meta_data) تُنقل الى
 *    الحقول الحقيقية حيث هي فارغة.
 */
return new class extends Migration
{
    public function up(): void
    {
        $products = config('items.table_prefix').'products';

        DB::table($products)->whereNull('stock_quantity')->update(['stock_quantity' => 0]);

        Schema::table($products, function (Blueprint $table) {
            $table->decimal('stock_quantity', 18, 4)->default(0)->nullable(false)->change();
        });

        $balances = config('accounting.table_prefix', 'accounting_').'stock_balances';

        if (Schema::hasTable($balances)) {
            DB::statement("
                UPDATE {$products} p
                JOIN (SELECT product_id, SUM(quantity) AS quantity, SUM(weight) AS weight FROM {$balances} GROUP BY product_id) b
                    ON b.product_id = p.id
                SET p.stock_quantity = b.quantity, p.stock_weight = b.weight
            ");
        }

        $recipes = config('items.table_prefix').'product_cutting_recipes';

        if (Schema::hasTable($recipes)) {
            DB::statement("
                UPDATE {$products} p
                SET p.length = p.min_limit_1, p.min_limit_1 = NULL
                WHERE p.min_limit_1 > 0
                    AND (p.length IS NULL OR p.length = 0)
                    AND p.id IN (SELECT output_product_id FROM {$recipes})
            ");
        }

        foreach (['max_limit' => 'item_max', 'min_limit_1' => 'item_min1', 'min_limit_2' => 'item_min2'] as $column => $legacyKey) {
            DB::statement("
                UPDATE {$products}
                SET {$column} = CAST(JSON_UNQUOTE(JSON_EXTRACT(meta_data, '$.{$legacyKey}')) AS DECIMAL(10,2))
                WHERE ({$column} IS NULL OR {$column} = 0)
                    AND JSON_VALID(meta_data)
                    AND JSON_UNQUOTE(JSON_EXTRACT(meta_data, '$.{$legacyKey}')) REGEXP '^[0-9]+(\\\\.[0-9]+)?$'
                    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(meta_data, '$.{$legacyKey}')) AS DECIMAL(10,2)) > 0
            ");
        }
    }

    public function down(): void
    {
        // لا رجوع: الرصيد العشري لا يُعاد الى عدد صحيح دون فقد، ونقل الحدود تصحيح للبيانات.
    }
};
