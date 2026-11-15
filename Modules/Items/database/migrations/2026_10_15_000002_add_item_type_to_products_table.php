<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طبيعة الصنف: سلعة او خدمة. الخدمة لا تدخل المخزون، ولها نوع (عامة او نقل)
 * ومصدر تكلفة افتراضي (داخلية او جهة خارجية) يُقترح في بنود البيع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('items.table_prefix').'products', function (Blueprint $table) {
            $table->string('item_type', 20)->default('goods')->after('pricing_type');
            $table->string('service_kind', 20)->nullable()->after('item_type');
            $table->string('service_cost_source', 20)->nullable()->after('service_kind');

            $table->index('item_type', 'items_products_item_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table(config('items.table_prefix').'products', function (Blueprint $table) {
            $table->dropIndex('items_products_item_type_idx');
            $table->dropColumn(['item_type', 'service_kind', 'service_cost_source']);
        });
    }
};
