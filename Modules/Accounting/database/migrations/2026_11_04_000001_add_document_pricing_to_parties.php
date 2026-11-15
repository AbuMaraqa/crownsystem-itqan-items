<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تسعير سطور المستندات لكل طرف: آخر سعر (خيار يفعّل، كما في نوع الحساب بالنظام القديم)
 * وقائمة اسعار. تصنيف الزبائن يقابل نوع الحساب، وقيمة الطرف نفسه تغلب قيمة تصنيفه
 * كما في نسبة الربح. المورد بلا تصنيف، فتُضبط له مباشرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        $priceLists = config('items.table_prefix').'price_lists';

        Schema::table(config('accounting.table_prefix').'customer_groups', function (Blueprint $table) use ($priceLists) {
            $table->boolean('use_last_price')->default(false)->after('profit_margin');
            $table->foreignId('price_list_id')->nullable()->after('use_last_price')->constrained($priceLists)->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) use ($priceLists) {
            // null: يتبع تصنيف الزبون.
            $table->boolean('use_last_price')->nullable();
            $table->foreignId('price_list_id')->nullable()->constrained($priceLists)->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_id');
            $table->dropColumn('use_last_price');
        });

        Schema::table(config('accounting.table_prefix').'customer_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_id');
            $table->dropColumn('use_last_price');
        });
    }
};
