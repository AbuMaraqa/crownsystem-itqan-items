<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وزن رصيد الصنف بالكغم في كل المخازن، بجانب «الكمية» (stock_quantity). يحدّثه دفتر المخزون،
 * وتجمعه شجرة الاصناف لكل صنف رئيسي (نوع الالمنيوم) من اصنافه الفرعية.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('items.table_prefix').'products';

        if (Schema::hasColumn($table, 'stock_weight')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->decimal('stock_weight', 18, 4)->default(0)->after('stock_quantity');
        });
    }

    public function down(): void
    {
        $table = config('items.table_prefix').'products';

        if (Schema::hasColumn($table, 'stock_weight')) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('stock_weight'));
        }
    }
};
