<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * اللون (الدهان) قائمة واحدة لكل الماركات. في إتقان كان اللون تصنيفاً فتكرر كل
 * مقطع مرة لكل لون (367 الف صنف = 8,727 اسماً)، وهنا يصبح اللون صفة للصنف الابن.
 * «بلا لون» صف نظامي للاصناف الموزونة التي لا ألوان لها (المطاط بالوزن)، فيبقى
 * مفتاح سعر الكيلو ورصيده (التصنيف × اللون) كاملاً دائماً.
 * التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §5.2 والقرار C-3.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('items.table_prefix').'finishes';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('code', 50)->nullable();
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->foreign('created_by', 'items_finishes_created_by_fk')->references('id')->on('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();

                $table->unique('name', 'items_finishes_name_unique');
            });
        }

        if (! DB::table($tableName)->where('is_system', true)->exists()) {
            DB::table($tableName)->insert([
                'name' => 'بلا لون',
                'code' => 'none',
                'is_system' => true,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('items.table_prefix').'finishes');
    }
};
