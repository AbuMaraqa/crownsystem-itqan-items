<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'consolidated_purchase_order_item_pieces';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('consolidated_purchase_order_item_id');
            $table->foreign('consolidated_purchase_order_item_id', 'acct_consolidated_po_item_pieces_item_fk')
                ->references('id')
                ->on(config('accounting.table_prefix').'consolidated_purchase_order_items')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreign('product_id', 'acct_consolidated_po_item_pieces_product_fk')
                ->references('id')
                ->on(config('items.table_prefix').'products')
                ->nullOnDelete();
            $table->string('barcode')->nullable();
            $table->string('description')->nullable();
            $table->decimal('piece_count', 18, 4)->default(1);
            $table->decimal('piece_length', 18, 4)->nullable();
            $table->decimal('weight_per_meter', 18, 4)->nullable();
            $table->decimal('total_weight', 18, 4)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by', 'acct_consolidated_po_item_pieces_created_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['consolidated_purchase_order_item_id', 'product_id'], 'acct_consolidated_po_item_pieces_item_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'consolidated_purchase_order_item_pieces');
    }
};
