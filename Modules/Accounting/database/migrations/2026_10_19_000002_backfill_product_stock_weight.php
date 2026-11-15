<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * يملأ وزن الرصيد (items_products.stock_weight) من ارصدة المخازن الحالية، مرة واحدة.
 * بعد ذلك يحدّثه StockLedgerService مع كل حركة.
 */
return new class extends Migration
{
    public function up(): void
    {
        $products = config('items.table_prefix').'products';
        $balances = config('accounting.table_prefix').'stock_balances';

        if (! Schema::hasColumn($products, 'stock_weight') || ! Schema::hasTable($balances)) {
            return;
        }

        // صيغة قياسية (بلا UPDATE ... JOIN الخاصة بـ MySQL) لتعمل على MySQL وPostgreSQL.
        DB::statement("UPDATE {$products}
            SET stock_weight = (SELECT SUM(b.weight) FROM {$balances} b WHERE b.product_id = {$products}.id)
            WHERE id IN (SELECT product_id FROM {$balances})");
    }

    public function down(): void
    {
        //
    }
};
