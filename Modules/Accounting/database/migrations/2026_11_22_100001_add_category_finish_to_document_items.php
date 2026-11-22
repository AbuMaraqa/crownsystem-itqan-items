<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سطر مجموعة السعر (المرحلة 2 من اعادة تصميم الاصناف): السطر الرئيسي لصنف الوزن هو (التصنيف × اللون)
 * مثل «الوبين — أبيض»، بلا صنف (product_id فارغ)، وعليه سعر الكيلو، وقطعه متغيّرات ذلك اللون.
 * يُمنع حذف مجموعة سعر عليها سطور مستندات.
 */
return new class extends Migration
{
    /** @var array<string, string> الجدول => اختصار اسم المفتاح */
    private const TABLES = [
        'sales_quotation_items' => 'sq',
        'sales_order_items' => 'so',
        'sales_invoice_items' => 'si',
        'sales_return_items' => 'sr',
        'purchase_order_items' => 'po',
        'purchase_invoice_items' => 'pi',
        'purchase_return_items' => 'pr',
    ];

    public function up(): void
    {
        $prefix = config('accounting.table_prefix');
        $groups = config('items.table_prefix').'category_finishes';

        foreach (self::TABLES as $table => $short) {
            if (! Schema::hasTable($prefix.$table) || Schema::hasColumn($prefix.$table, 'category_finish_id')) {
                continue;
            }

            Schema::table($prefix.$table, function (Blueprint $blueprint) use ($groups, $short) {
                $blueprint->foreignId('category_finish_id')->nullable()->after('product_id')
                    ->constrained($groups, 'id', "acct_{$short}_items_cat_fin_fk")->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        foreach (self::TABLES as $table => $short) {
            if (Schema::hasColumn($prefix.$table, 'category_finish_id')) {
                Schema::table($prefix.$table, function (Blueprint $blueprint) use ($short) {
                    $blueprint->dropForeign("acct_{$short}_items_cat_fin_fk");
                    $blueprint->dropColumn('category_finish_id');
                });
            }
        }
    }
};
