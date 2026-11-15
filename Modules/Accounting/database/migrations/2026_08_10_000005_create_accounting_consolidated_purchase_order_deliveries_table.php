<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_deliveries';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_purchase_order_id');
            $table->foreign('consolidated_purchase_order_id', 'acct_cpo_deliveries_order_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_orders')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('delivery_company_id')->nullable();
            $table->foreign('delivery_company_id', 'acct_cpo_deliveries_company_fk')->references('id')->on('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by', 'acct_cpo_deliveries_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['consolidated_purchase_order_id', 'delivery_company_id'], 'acct_cpo_deliveries_order_company_idx');
        });

        $itemsTableName = config('accounting.table_prefix').'consolidated_purchase_order_delivery_cost_items';

        Schema::create($itemsTableName, function (Blueprint $table) use ($tableName) {
            $table->id();
            $table->foreignId('delivery_id');
            $table->foreign('delivery_id', 'acct_cpo_delivery_cost_items_delivery_fk')
                ->references('id')
                ->on($tableName)
                ->cascadeOnDelete();
            $table->unsignedBigInteger('cost_element_id')->nullable();
            $table->foreign('cost_element_id', 'acct_cpo_delivery_cost_items_element_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'delivery_cost_elements')
                ->nullOnDelete();
            $table->decimal('amount', 18, 4)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['delivery_id', 'cost_element_id'], 'acct_cpo_delivery_cost_items_delivery_element_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_delivery_cost_items');
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_deliveries');
    }
};
