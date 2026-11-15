<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقلا "الصنف المرادف" و"الرف" في تبويب معلومات بطاقة الصنف كما في النظام القديم.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('items.table_prefix').'products';

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            $blueprint->foreignId('synonym_product_id')
                ->nullable()
                ->after('main_product_id')
                ->constrained($table, 'id', 'items_products_synonym_product_id_foreign')
                ->nullOnDelete();
            $blueprint->string('shelf', 50)->nullable()->after('max_limit');
        });
    }

    public function down(): void
    {
        Schema::table(config('items.table_prefix').'products', function (Blueprint $blueprint) {
            $blueprint->dropForeign('items_products_synonym_product_id_foreign');
            $blueprint->dropColumn(['synonym_product_id', 'shelf']);
        });
    }
};
