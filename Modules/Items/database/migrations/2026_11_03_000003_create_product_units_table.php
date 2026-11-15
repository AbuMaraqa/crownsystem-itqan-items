<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الوحدات المكافئة للصنف. المعامل هو عدد الوحدات الاساسية في هذه الوحدة، والوحدة
 * الاساسية هي products.unit_id بمعامل 1 ولا صف لها هنا.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('items.table_prefix');

        Schema::create($prefix.'product_units', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('product_id')->constrained($prefix.'products')->cascadeOnDelete();
            // الوحدة المستخدمة في صنف لا تُحذف، فبدونها لا يُفهم المعامل.
            $table->foreignId('unit_id')->constrained($prefix.'units')->restrictOnDelete();
            $table->decimal('factor', 18, 6);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'unit_id'], 'items_product_units_product_unit_unique');
            $table->unique(['product_id', 'factor'], 'items_product_units_product_factor_unique');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `'.DB::getTablePrefix().$prefix.'product_units` ADD CONSTRAINT `items_product_units_factor_positive` CHECK (`factor` > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('items.table_prefix').'product_units');
    }
};
