<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس تجعل عدّ الأصناف يُقرأ من الفهرس وحده بدل قراءة كل صف:
 * - قائمة الأصناف تعدّ الأصناف الرئيسية (`parent_id` و`main_product_id` فارغان، غير محذوفة): مع مئات آلاف
 *   الأصناف كان العدّ يقرأ الصفوف كاملة.
 * - شجرة التصنيفات تعدّ أصناف كل تصنيف عبر جدول الربط: الفهرس المركّب يغطي العدّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('items_products') && ! Schema::hasIndex('items_products', 'items_products_list_count_idx')) {
            Schema::table('items_products', function (Blueprint $table) {
                $table->index(['parent_id', 'main_product_id', 'deleted_at'], 'items_products_list_count_idx');
            });
        }

        if (Schema::hasTable('items_products_categories') && ! Schema::hasIndex('items_products_categories', 'items_products_categories_cat_prod_idx')) {
            Schema::table('items_products_categories', function (Blueprint $table) {
                $table->index(['category_id', 'product_id'], 'items_products_categories_cat_prod_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('items_products', 'items_products_list_count_idx')) {
            Schema::table('items_products', fn (Blueprint $table) => $table->dropIndex('items_products_list_count_idx'));
        }

        if (Schema::hasIndex('items_products_categories', 'items_products_categories_cat_prod_idx')) {
            Schema::table('items_products_categories', fn (Blueprint $table) => $table->dropIndex('items_products_categories_cat_prod_idx'));
        }
    }
};
