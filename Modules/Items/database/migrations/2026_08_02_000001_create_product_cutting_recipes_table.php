<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('items.table_prefix').'product_cutting_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('output_product_id')
                ->constrained(config('items.table_prefix').'products')
                ->cascadeOnDelete();
            $table->foreignId('source_product_id')
                ->constrained(config('items.table_prefix').'products')
                ->cascadeOnDelete();
            $table->decimal('pieces_per_unit', 10, 4)->default(0);
            $table->timestamps();

            $table->unique(['output_product_id', 'source_product_id'], 'item_cutting_recipes_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('items.table_prefix').'product_cutting_recipes');
    }
};
