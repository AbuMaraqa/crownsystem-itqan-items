<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_price_offer_items';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_offer_id');
            $table->foreign('price_offer_id', 'acct_cpo_price_offer_items_offer_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_order_price_offers')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('consolidated_purchase_order_item_id')->nullable();
            $table->foreign('consolidated_purchase_order_item_id', 'acct_cpo_price_offer_items_cpo_item_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_order_items')
                ->nullOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount_rate', 8, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->string('bonus_note')->nullable();
            $table->decimal('line_total', 18, 4)->default(0);
            $table->timestamps();

            $table->index(['price_offer_id', 'consolidated_purchase_order_item_id'], 'acct_cpo_price_offer_items_offer_cpo_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_price_offer_items');
    }
};
