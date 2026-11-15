<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شجرة اصناف الوزن: الصنف الفرعي (مقطع الالمنيوم) يتبع صنفه الرئيسي الذي
 * يحمل سعر الكيلوغرام. parent_id محجوز لمتغيرات الصنف فلا يصلح لهذا.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('items.table_prefix').'products';

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            $blueprint->foreignId('main_product_id')
                ->nullable()
                ->after('parent_id')
                ->constrained($table, 'id', 'items_products_main_product_id_foreign')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table(config('items.table_prefix').'products', function (Blueprint $blueprint) {
            $blueprint->dropForeign('items_products_main_product_id_foreign');
            $blueprint->dropColumn('main_product_id');
        });
    }
};
