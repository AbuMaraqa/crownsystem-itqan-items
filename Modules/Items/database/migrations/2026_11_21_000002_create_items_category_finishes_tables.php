<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مجموعة السعر: (التصنيف × اللون)، مثل «الوبين × بدون دهان». عليها سعر الكيلو لكل
 * قائمة سعر، كما كان سعر الكيلو في إتقان على تصنيف (الماركة + اللون)، وعليها لاحقاً
 * رصيد الكيلوغرامات وتكلفته بدل «الصنف الرئيسي» الوهمي.
 * legacy_code رقم تصنيف إتقان الذي جاءت منه (مثل 00009).
 * التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §5.3 والقرار C-2.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('items.table_prefix');
        $groups = $prefix.'category_finishes';
        $prices = $prefix.'category_finish_prices';

        if (! Schema::hasTable($groups)) {
            Schema::create($groups, function (Blueprint $table) use ($prefix) {
                $table->id();
                $table->unsignedBigInteger('category_id');
                $table->foreign('category_id', 'items_cat_fin_category_fk')->references('id')->on($prefix.'categories')->cascadeOnDelete();
                $table->unsignedBigInteger('finish_id');
                $table->foreign('finish_id', 'items_cat_fin_finish_fk')->references('id')->on($prefix.'finishes')->restrictOnDelete();
                $table->string('legacy_code', 50)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['category_id', 'finish_id'], 'items_cat_fin_unique');
                $table->index('legacy_code', 'items_cat_fin_legacy_idx');
            });
        }

        if (! Schema::hasTable($prices)) {
            Schema::create($prices, function (Blueprint $table) use ($prefix, $groups) {
                $table->id();
                $table->unsignedBigInteger('category_finish_id');
                $table->foreign('category_finish_id', 'items_cat_fin_price_group_fk')->references('id')->on($groups)->cascadeOnDelete();
                $table->unsignedBigInteger('price_list_id');
                $table->foreign('price_list_id', 'items_cat_fin_price_list_fk')->references('id')->on($prefix.'price_lists')->cascadeOnDelete();
                $table->decimal('sell_price', 18, 4)->default(0);
                $table->decimal('buy_price', 18, 4)->default(0);
                $table->unsignedBigInteger('source_price_list_id')->nullable();
                $table->foreign('source_price_list_id', 'items_cat_fin_price_source_fk')->references('id')->on($prefix.'price_lists')->nullOnDelete();
                $table->decimal('source_factor', 18, 6)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->foreign('created_by', 'items_cat_fin_price_created_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['category_finish_id', 'price_list_id'], 'items_cat_fin_price_unique');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('items.table_prefix');

        Schema::dropIfExists($prefix.'category_finish_prices');
        Schema::dropIfExists($prefix.'category_finishes');
    }
};
