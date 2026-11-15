<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'sales_return_items';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')
                ->constrained(config('accounting.table_prefix').'sales_returns')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('sales_invoice_item_id')->nullable();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained(config('items.table_prefix').'products')
                ->nullOnDelete();
            $table->string('barcode')->nullable()->index('acct_sales_return_items_barcode_idx');
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

            $table->index(['sales_return_id', 'product_id'], 'acct_sales_return_items_return_product_idx');
            $table->index('sales_invoice_item_id', 'acct_sales_return_item_invoice_item_idx');

            $table->foreign('sales_invoice_item_id', 'acct_sr_items_invoice_item_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'sales_invoice_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'sales_return_items');
    }
};
