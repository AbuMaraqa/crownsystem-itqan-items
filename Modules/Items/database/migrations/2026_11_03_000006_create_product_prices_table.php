<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سعر البيع والشراء لكل قائمة × صنف × وحدة (بلا وحدة: الوحدة الاساسية).
 * ان وُجدت قائمة مصدر فالسعر = سعر الصنف نفسه في تلك القائمة × source_factor.
 *
 * MySQL يسمح بعدة NULL في الفهرس الفريد، فيكون القيد على عمود مولَّد يجعل
 * "بلا وحدة" صفراً. ويلزم ان يكون VIRTUAL: العمود STORED يرفض قيداً اجنبياً
 * بحذف متتالٍ على عموده الاساسي (product_unit_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('items.table_prefix');

        Schema::create($prefix.'product_prices', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('price_list_id')->constrained($prefix.'price_lists')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained($prefix.'products')->cascadeOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained($prefix.'product_units')->cascadeOnDelete();
            $table->unsignedBigInteger('unit_key')->virtualAs('COALESCE(`product_unit_id`, 0)');
            $table->decimal('sell_price', 18, 4)->default(0);
            $table->decimal('buy_price', 18, 4)->default(0);
            $table->foreignId('source_price_list_id')->nullable()->constrained($prefix.'price_lists')->nullOnDelete();
            $table->decimal('source_factor', 18, 6)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['price_list_id', 'product_id', 'unit_key'], 'items_product_prices_list_product_unit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('items.table_prefix').'product_prices');
    }
};
