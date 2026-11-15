<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'sales_order_item_pieces';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_item_id')
                ->constrained(config('accounting.table_prefix').'sales_order_items')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained(config('items.table_prefix').'products')
                ->nullOnDelete();
            $table->string('barcode')->nullable();
            $table->string('description')->nullable();
            $table->decimal('piece_count', 18, 4)->default(1);
            $table->decimal('piece_length', 18, 4)->nullable();
            $table->decimal('weight_per_meter', 18, 4)->nullable();
            $table->decimal('total_weight', 18, 4)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sales_order_item_id', 'product_id'], 'acct_so_item_pieces_item_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'sales_order_item_pieces');
    }
};
