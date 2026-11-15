<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ملحقات الصنف: اصناف تُقترح عند بيعه (اقتراح لا إلزام)، مع كمية مقترحة لكل
 * وحدة واحدة من الصنف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('items.table_prefix').'product_accessories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained(config('items.table_prefix').'products')
                ->cascadeOnDelete();
            $table->foreignId('accessory_product_id')
                ->constrained(config('items.table_prefix').'products')
                ->cascadeOnDelete();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'accessory_product_id'], 'item_product_accessories_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('items.table_prefix').'product_accessories');
    }
};
