<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفتر المخزون:
 * - stock_movements: كل حركة دخول (+) او خروج (−) بكميتها ووزنها وكلفتها ومستندها.
 *   هي المرجع؛ الارصدة تُشتق منها.
 * - stock_balances: الرصيد الحالي لكل صنف في كل مخزن وموقع (location_id = 0 بلا موقع)،
 *   يُحدَّث مع كل حركة ليكون الاستعلام سريعاً.
 * - product_costs: متوسط التكلفة المرجح المتحرك لكل صنف (لكل الشركة).
 *
 * الكمية بوحدة الصنف: للاصناف الموزونة (بروفيلات الالمنيوم) هي عدد القطع (القضبان)،
 * والوزن بالكيلوغرام يُحفظ بجانبها.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');
        $products = config('items.table_prefix').'products';

        if (! Schema::hasTable($prefix.'stock_movements')) {
            Schema::create($prefix.'stock_movements', function (Blueprint $table) use ($prefix, $products) {
                $table->id();
                $table->foreignId('product_id')->constrained($products, 'id', 'acct_stock_moves_product_fk')->restrictOnDelete();
                $table->foreignId('warehouse_id')->constrained($prefix.'warehouses', 'id', 'acct_stock_moves_warehouse_fk')->restrictOnDelete();
                $table->unsignedBigInteger('location_id')->default(0);
                $table->date('movement_date');
                $table->string('type', 30);
                $table->decimal('quantity', 18, 4);
                $table->decimal('weight', 18, 4)->default(0);
                $table->decimal('unit_cost', 20, 6)->default(0);
                $table->decimal('total_cost', 18, 4)->default(0);
                $table->nullableMorphs('sourceable', 'acct_stock_moves_source_idx');
                $table->unsignedBigInteger('source_line_id')->nullable();
                $table->string('reference_no')->nullable();
                $table->string('description')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'acct_stock_moves_created_by_fk')->nullOnDelete();
                $table->timestamps();

                $table->index(['product_id', 'warehouse_id', 'movement_date'], 'acct_stock_moves_card_idx');
                $table->index(['warehouse_id', 'movement_date'], 'acct_stock_moves_wh_date_idx');
            });
        }

        if (! Schema::hasTable($prefix.'stock_balances')) {
            Schema::create($prefix.'stock_balances', function (Blueprint $table) use ($prefix, $products) {
                $table->id();
                $table->foreignId('product_id')->constrained($products, 'id', 'acct_stock_bal_product_fk')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained($prefix.'warehouses', 'id', 'acct_stock_bal_warehouse_fk')->cascadeOnDelete();
                $table->unsignedBigInteger('location_id')->default(0);
                $table->decimal('quantity', 18, 4)->default(0);
                $table->decimal('weight', 18, 4)->default(0);
                $table->timestamps();

                $table->unique(['product_id', 'warehouse_id', 'location_id'], 'acct_stock_bal_unique');
                $table->index(['warehouse_id', 'location_id'], 'acct_stock_bal_location_idx');
            });
        }

        if (! Schema::hasTable($prefix.'product_costs')) {
            Schema::create($prefix.'product_costs', function (Blueprint $table) use ($products) {
                $table->id();
                $table->foreignId('product_id')->unique('acct_product_costs_product_unique')
                    ->constrained($products, 'id', 'acct_product_costs_product_fk')->cascadeOnDelete();
                $table->decimal('quantity', 18, 4)->default(0);
                $table->decimal('value', 18, 4)->default(0);
                $table->decimal('average_cost', 20, 6)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        Schema::dropIfExists($prefix.'product_costs');
        Schema::dropIfExists($prefix.'stock_balances');
        Schema::dropIfExists($prefix.'stock_movements');
    }
};
