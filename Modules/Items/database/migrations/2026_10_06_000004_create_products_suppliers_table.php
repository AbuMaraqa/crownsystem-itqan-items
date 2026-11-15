<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * موردو الصنف (تبويب "مورد" في بطاقة الصنف). المورد مستخدم بدور Supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('items.table_prefix').'products_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained(config('items.table_prefix').'products')
                ->cascadeOnDelete();
            $table->foreignId('supplier_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'supplier_id'], 'items_products_suppliers_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('items.table_prefix').'products_suppliers');
    }
};
