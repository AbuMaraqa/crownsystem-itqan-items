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

        $translations = config('items.table_prefix') . 'product_translations';

        // FULLTEXT وطول البادئة name(191) خاصان بـ MySQL؛ على غيره يكفي فهرس عادي (البحث يرجع الى LIKE).
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('CREATE FULLTEXT INDEX items_product_translations_name_fulltext ON ' . $translations . ' (`name`)');
            DB::statement('CREATE INDEX items_product_translations_locale_name_idx ON ' . $translations . ' (`locale`, `name`(191))');

            return;
        }

        Schema::table($translations, function (Blueprint $table) {
            $table->index(['locale', 'name'], 'items_product_translations_locale_name_idx');
        });
    }

    public function down(): void
    {
        $translations = config('items.table_prefix') . 'product_translations';

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX items_product_translations_locale_name_idx ON ' . $translations);
            DB::statement('DROP INDEX items_product_translations_name_fulltext ON ' . $translations);
        } else {
            Schema::table($translations, function (Blueprint $table) {
                $table->dropIndex('items_product_translations_locale_name_idx');
            });
        }

        Schema::table(config('items.table_prefix') . 'products', function (Blueprint $table) {
            $table->dropIndex('items_products_parent_barcode_idx');
            $table->dropIndex('items_products_parent_sku_idx');
        });
    }
};
