<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'sales_order_items';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')
                ->constrained(config('accounting.table_prefix').'sales_orders')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('sales_quotation_item_id')->nullable();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained(config('items.table_prefix').'products')
                ->nullOnDelete();
            $table->string('barcode')->nullable()->index('acct_sales_order_items_barcode_idx');
            $table->string('description')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount_rate', 8, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['sales_order_id', 'product_id'], 'acct_sales_order_items_order_product_idx');
            $table->index('sales_quotation_item_id', 'acct_sales_order_item_quote_item_idx');

            $table->foreign('sales_quotation_item_id', 'acct_so_items_quote_item_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'sales_quotation_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'sales_order_items');
    }
};
