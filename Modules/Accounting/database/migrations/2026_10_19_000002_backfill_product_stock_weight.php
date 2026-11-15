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

        DB::statement("UPDATE {$products} p
            JOIN (SELECT product_id, SUM(weight) AS weight FROM {$balances} GROUP BY product_id) b ON b.product_id = p.id
            SET p.stock_weight = b.weight");
    }

    public function down(): void
    {
        //
    }
};
