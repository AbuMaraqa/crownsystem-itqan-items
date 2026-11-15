<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('items.table_prefix') . 'products', function (Blueprint $table) {
            $table->index(['parent_id', 'sku'], 'items_products_parent_sku_idx');
            $table->index(['parent_id', 'barcode'], 'items_products_parent_barcode_idx');
        });

        DB::statement('CREATE FULLTEXT INDEX items_product_translations_name_fulltext ON ' . config('items.table_prefix') . 'product_translations (`name`)');
        DB::statement('CREATE INDEX items_product_translations_locale_name_idx ON ' . config('items.table_prefix') . 'product_translations (`locale`, `name`(191))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX items_product_translations_locale_name_idx ON ' . config('items.table_prefix') . 'product_translations');
        DB::statement('DROP INDEX items_product_translations_name_fulltext ON ' . config('items.table_prefix') . 'product_translations');

        Schema::table(config('items.table_prefix') . 'products', function (Blueprint $table) {
            $table->dropIndex('items_products_parent_barcode_idx');
            $table->dropIndex('items_products_parent_sku_idx');
        });
    }
};
