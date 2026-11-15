<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مواد العميل: ما يحضره العميل ليُقدَّم عليه خدمة (مثل خام ore للمعالجة).
 * ملك العميل لا الشركة، فلا قيمة لها ولا قيد ولا تدخل المخزون (IAS 2 / IFRS 15)،
 * وتُسجَّل للاثبات والمتابعة ولحساب كمية الخدمة المسعّرة بالوزن.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'sales_order_customer_materials';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')
                ->constrained(config('accounting.table_prefix').'sales_orders', 'id', 'acct_so_materials_order_fk')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained(config('items.table_prefix').'products', 'id', 'acct_so_materials_product_fk')
                ->nullOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('weight', 18, 4)->default(0);
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'acct_so_materials_created_by_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['sales_order_id', 'sort_order'], 'acct_so_materials_order_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'sales_order_customer_materials');
    }
};
