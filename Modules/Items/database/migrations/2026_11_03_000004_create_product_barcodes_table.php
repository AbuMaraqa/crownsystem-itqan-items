<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Items\Support\Search\SearchCapabilities;

/**
 * الباركودات الاضافية للصنف، والباركود الرئيسي يبقى في products.barcode.
 * كمية العبوة: عدد ما يمثله الباركود من وحدته (مثلاً باركود الكرتونة).
 * is_prefix: يطابق كل ما يبدأ به (النجمة في بداية الباركود في النظام القديم).
 * sort_order: موضع الباركود الاصلي، فطباعة الالمنيوم تختار الباركود بموضعه.
 *
 * الباركود ليس فريداً بين الاصناف: في البيانات الحقيقية يتكرر نفس باركود المقطع
 * تحت كل لون. الفرادة لكل صنف فقط.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('items.table_prefix');

        Schema::create($prefix.'product_barcodes', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('product_id')->constrained($prefix.'products')->cascadeOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained($prefix.'product_units')->nullOnDelete();
            $table->string('barcode');
            $table->decimal('pack_quantity', 18, 6)->default(1);
            $table->boolean('is_prefix')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('barcode', 'items_product_barcodes_barcode_idx');
            $table->unique(['product_id', 'barcode'], 'items_product_barcodes_product_barcode_unique');
        });

        // قدرات البحث محفوظة، فيُبلَّغ البحث بالجدول الجديد.
        SearchCapabilities::forget();
    }

    public function down(): void
    {
        Schema::dropIfExists(config('items.table_prefix').'product_barcodes');

        SearchCapabilities::forget();
    }
};
