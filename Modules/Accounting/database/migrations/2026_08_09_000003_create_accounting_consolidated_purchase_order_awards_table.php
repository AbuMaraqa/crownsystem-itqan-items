<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_awards';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_purchase_order_id');
            $table->foreign('consolidated_purchase_order_id', 'acct_cpo_awards_order_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_orders')
                ->cascadeOnDelete();
            $table->foreignId('price_offer_id');
            $table->foreign('price_offer_id', 'acct_cpo_awards_offer_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_order_price_offers')
                ->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('consolidated_purchase_order_id', 'acct_cpo_awards_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_awards');
    }
};
