<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Enums\ProductionOrderStatus;

/**
 * أمر الإنتاج: تنفيذ طلبية بيع في قسم الإنتاج. تكلفته مجموع الاصناف المستهلكة
 * والأجور والخدمات (داخلية او لدى جهة خارجية كالدهان)، وتُحسب تلقائياً وتُخزَّن
 * مجاميعها هنا مع تكلفة الوحدة وقيمة البيع للمقارنة.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');
        $tableName = $prefix.'production_orders';

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('sales_order_id')
                ->nullable()
                ->constrained($prefix.'sales_orders', 'id', 'acct_prod_orders_sales_order_fk')
                ->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('users', 'id', 'acct_prod_orders_client_fk')->nullOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained(config('branch.table_prefix').'branches', 'id', 'acct_prod_orders_branch_fk')
                ->nullOnDelete();
            $table->foreignId('warehouse_id')
                ->nullable()
                ->constrained($prefix.'warehouses', 'id', 'acct_prod_orders_warehouse_fk')
                ->nullOnDelete();
            $table->foreignId('currency_id')->constrained('currencies', 'id', 'acct_prod_orders_currency_fk')->restrictOnDelete();
            $table->string('reference_no')->unique('acct_prod_orders_reference_unique');
            $table->unsignedTinyInteger('status')->default(ProductionOrderStatus::DRAFT->value());
            $table->date('order_date');
            $table->date('due_date')->nullable();
            $table->decimal('produced_quantity', 18, 4)->default(1);
            $table->decimal('materials_cost', 18, 4)->default(0);
            $table->decimal('labor_cost', 18, 4)->default(0);
            $table->decimal('services_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('sales_amount', 18, 4)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'acct_prod_orders_created_by_fk')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'order_date'], 'acct_prod_orders_status_date_idx');
            $table->index(['client_id', 'order_date'], 'acct_prod_orders_client_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('accounting.table_prefix').'production_orders');
    }
};
