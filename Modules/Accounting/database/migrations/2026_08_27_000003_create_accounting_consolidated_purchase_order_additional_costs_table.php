<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_additional_costs';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_purchase_order_id');
            $table->foreign('consolidated_purchase_order_id', 'acct_cpo_add_costs_order_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_orders')
                ->cascadeOnDelete();
            $table->foreignId('additional_cost_type_id');
            $table->foreign('additional_cost_type_id', 'acct_cpo_add_costs_type_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'additional_cost_types')
                ->restrictOnDelete();
            $table->text('details')->nullable();
            $table->decimal('amount', 18, 4)->default(0);
            $table->foreignId('currency_id');
            $table->foreign('currency_id', 'acct_cpo_add_costs_currency_fk')->references('id')->on('currencies')->restrictOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by', 'acct_cpo_add_costs_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['consolidated_purchase_order_id', 'additional_cost_type_id'], 'acct_cpo_add_costs_order_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_additional_costs');
    }
};
