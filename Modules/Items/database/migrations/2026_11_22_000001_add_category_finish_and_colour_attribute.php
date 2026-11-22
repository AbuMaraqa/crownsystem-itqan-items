<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Items\Enums\AttributeType;

/**
 * - category_finish_id على المتغيّر: مجموعة السعر (التصنيف × اللون) التي جاء منها، فيُقرأ
 *   سعر الكيلو منها مباشرة ولو كان تصنيف الصنف الاب غيرها (المقطع قد يقع في مجموعات من
 *   أنظمة مختلفة).
 * - مرآة المتجر: سمة «اللون» النظامية (slug colour)، ولكل لون قيمة فيها
 *   (items_finishes.attribute_value_id)، فيرى المتجر الإلكتروني الألوان متغيرات عبر السمات.
 * التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §10 (R-2، R-6).
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('items.table_prefix');

        Schema::table($prefix.'products', function (Blueprint $table) use ($prefix) {
            if (! Schema::hasColumn($prefix.'products', 'category_finish_id')) {
                $table->unsignedBigInteger('category_finish_id')->nullable();
                $table->foreign('category_finish_id', 'items_products_cat_fin_fk')->references('id')->on($prefix.'category_finishes')->nullOnDelete();
                $table->index('category_finish_id', 'items_products_cat_fin_idx');
            }
        });

        Schema::table($prefix.'finishes', function (Blueprint $table) use ($prefix) {
            if (! Schema::hasColumn($prefix.'finishes', 'attribute_value_id')) {
                $table->unsignedBigInteger('attribute_value_id')->nullable();
                $table->foreign('attribute_value_id', 'items_finishes_attr_value_fk')->references('id')->on($prefix.'attribute_values')->nullOnDelete();
            }
        });

        $now = now();
        $attributeId = DB::table($prefix.'attributes')->where('slug', 'colour')->value('id');

        if ($attributeId === null) {
            $attributeId = DB::table($prefix.'attributes')->insertGetId([
                'slug' => 'colour', 'type' => AttributeType::COLOR->value, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table($prefix.'attribute_translations')->insert([
                'attribute_id' => $attributeId, 'locale' => 'ar', 'name' => 'اللون', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $system = DB::table($prefix.'finishes')->where('is_system', true)->first();

        if ($system !== null && $system->attribute_value_id === null) {
            $valueId = DB::table($prefix.'attribute_values')->insertGetId([
                'attribute_id' => $attributeId, 'sort_order' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table($prefix.'attribute_values_translations')->insert([
                'attribute_value_id' => $valueId, 'locale' => 'ar', 'name' => $system->name, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table($prefix.'finishes')->where('id', $system->id)->update(['attribute_value_id' => $valueId]);
        }
    }

    public function down(): void
    {
        $prefix = config('items.table_prefix');

        Schema::table($prefix.'finishes', function (Blueprint $table) use ($prefix) {
            if (Schema::hasColumn($prefix.'finishes', 'attribute_value_id')) {
                $table->dropForeign('items_finishes_attr_value_fk');
                $table->dropColumn('attribute_value_id');
            }
        });

        Schema::table($prefix.'products', function (Blueprint $table) use ($prefix) {
            if (Schema::hasColumn($prefix.'products', 'category_finish_id')) {
                $table->dropForeign('items_products_cat_fin_fk');
                $table->dropIndex('items_products_cat_fin_idx');
                $table->dropColumn('category_finish_id');
            }
        });

        DB::table($prefix.'attributes')->where('slug', 'colour')->delete();
    }
};
