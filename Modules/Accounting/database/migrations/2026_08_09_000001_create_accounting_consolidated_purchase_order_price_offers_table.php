<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_price_offers';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_purchase_order_id');
            $table->foreign('consolidated_purchase_order_id', 'acct_cpo_price_offers_order_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_orders')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->foreign('supplier_id', 'acct_cpo_price_offers_supplier_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreignId('currency_id');
            $table->foreign('currency_id', 'acct_cpo_price_offers_currency_fk')->references('id')->on('currencies')->restrictOnDelete();
            $table->date('offer_date');
            $table->text('notes')->nullable();
            $table->decimal('sub_total', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by', 'acct_cpo_price_offers_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['consolidated_purchase_order_id', 'supplier_id'], 'acct_cpo_price_offers_order_supplier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_price_offers');
    }
};
