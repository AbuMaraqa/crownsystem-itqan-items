<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_shipments';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_purchase_order_id');
            $table->foreign('consolidated_purchase_order_id', 'acct_cpo_shipments_order_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_orders')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('shipping_company_id')->nullable();
            $table->foreign('shipping_company_id', 'acct_cpo_shipments_company_fk')->references('id')->on('users')->nullOnDelete();
            $table->unsignedTinyInteger('shipping_type');
            $table->unsignedTinyInteger('shipping_classification');
            $table->foreignId('currency_id');
            $table->foreign('currency_id', 'acct_cpo_shipments_currency_fk')->references('id')->on('currencies')->restrictOnDelete();
            $table->decimal('price', 18, 4)->default(0);
            $table->decimal('net_weight', 18, 4)->nullable();
            $table->decimal('gross_weight', 18, 4)->nullable();
            $table->decimal('cbm', 18, 4)->nullable();
            $table->unsignedTinyInteger('shipping_terms')->nullable();
            $table->string('attachment_path')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by', 'acct_cpo_shipments_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['consolidated_purchase_order_id', 'shipping_company_id'], 'acct_cpo_shipments_order_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_shipments');
    }
};
