<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قوائم الاسعار. same_buy_sell: القائمة تحفظ سعر الشراء مساوياً لسعر البيع.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('items.table_prefix');

        Schema::create($prefix.'price_lists', function (Blueprint $table) {
            $table->id();
            $table->boolean('same_buy_sell')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create($prefix.'price_list_translations', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('locale')->index();
            $table->string('name');
            $table->foreignId('price_list_id')->constrained($prefix.'price_lists')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['price_list_id', 'locale'], 'items_price_list_translations_locale_unique');
        });
    }

    public function down(): void
    {
        $prefix = config('items.table_prefix');

        Schema::dropIfExists($prefix.'price_list_translations');
        Schema::dropIfExists($prefix.'price_lists');
    }
};
