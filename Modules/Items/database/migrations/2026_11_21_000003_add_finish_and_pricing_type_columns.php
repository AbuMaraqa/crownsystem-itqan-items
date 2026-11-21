<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * finish_id على الصنف: لون الصنف الابن (او «بلا لون» للموزون الذي لا ألوان له).
 * pricing_type على التصنيف: طريقة التسعير الافتراضية لاصنافه، بقيم الصنف نفسها
 * (weight او simple).
 * التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §5.1 و§5.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('items.table_prefix');

        Schema::table($prefix.'products', function (Blueprint $table) use ($prefix) {
            if (! Schema::hasColumn($prefix.'products', 'finish_id')) {
                $table->unsignedBigInteger('finish_id')->nullable();
                $table->foreign('finish_id', 'items_products_finish_fk')->references('id')->on($prefix.'finishes')->nullOnDelete();
                $table->index('finish_id', 'items_products_finish_idx');
            }
        });

        Schema::table($prefix.'categories', function (Blueprint $table) use ($prefix) {
            if (! Schema::hasColumn($prefix.'categories', 'pricing_type')) {
                $table->string('pricing_type', 20)->default('simple');
            }
        });
    }

    public function down(): void
    {
        $prefix = config('items.table_prefix');

        Schema::table($prefix.'products', function (Blueprint $table) use ($prefix) {
            if (Schema::hasColumn($prefix.'products', 'finish_id')) {
                $table->dropForeign('items_products_finish_fk');
                $table->dropIndex('items_products_finish_idx');
                $table->dropColumn('finish_id');
            }
        });

        Schema::table($prefix.'categories', function (Blueprint $table) use ($prefix) {
            if (Schema::hasColumn($prefix.'categories', 'pricing_type')) {
                $table->dropColumn('pricing_type');
            }
        });
    }
};
