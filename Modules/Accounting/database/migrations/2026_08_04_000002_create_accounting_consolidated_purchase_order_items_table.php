<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_items';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_purchase_order_id');
            $table->foreign('consolidated_purchase_order_id', 'acct_consolidated_po_items_order_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_orders')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('source_purchase_order_item_id')->nullable();
            $table->foreign('source_purchase_order_item_id', 'acct_consolidated_po_items_source_item_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'purchase_order_items')
                ->nullOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained(config('items.table_prefix').'products')
                ->nullOnDelete();
            $table->string('barcode')->nullable()->index('acct_consolidated_po_items_barcode_idx');
            $table->string('description')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount_rate', 8, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->decimal('total_weight', 18, 4)->default(0);
            $table->decimal('actual_weight', 18, 4)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['consolidated_purchase_order_id', 'product_id'], 'acct_consolidated_po_items_order_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_items');
    }
};
