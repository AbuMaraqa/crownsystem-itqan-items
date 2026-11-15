<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بنود تكلفة أمر الإنتاج، جدول لكل نوع:
 *
 * - الاصناف (المواد المستهلكة): الصنف الموزون يُكلَّف بوزنه × تكلفة الكغم، وغيره
 *   بكميته × تكلفة الوحدة.
 * - الأجور: العامل ونوع العمل، والكمية (ساعات، ايام، قطع، او مرة لمبلغ مقطوع) × الأجر.
 * - الخدمات: كالدهان، داخلية او لدى جهة خارجية (مسجلة او غير مسجلة)، الكمية × تكلفة الوحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');
        $orders = $prefix.'production_orders';
        $products = config('items.table_prefix').'products';

        if (! Schema::hasTable($prefix.'production_order_materials')) {
            Schema::create($prefix.'production_order_materials', function (Blueprint $table) use ($prefix, $orders, $products) {
                $table->id();
                $table->foreignId('production_order_id')->constrained($orders, 'id', 'acct_prod_materials_order_fk')->cascadeOnDelete();
                $table->unsignedBigInteger('sales_order_item_id')->nullable();
                $table->foreignId('product_id')->nullable()->constrained($products, 'id', 'acct_prod_materials_product_fk')->nullOnDelete();
                $table->string('description')->nullable();
                $table->string('pricing_type', 20)->default('simple');
                $table->decimal('quantity', 18, 4)->default(0);
                $table->decimal('weight', 18, 4)->default(0);
                $table->decimal('unit_cost', 18, 4)->default(0);
                $table->decimal('total_cost', 18, 4)->default(0);
                $table->text('notes')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'acct_prod_materials_created_by_fk')->nullOnDelete();
                $table->timestamps();

                $table->index(['production_order_id', 'sort_order'], 'acct_prod_materials_order_sort_idx');
                $table->foreign('sales_order_item_id', 'acct_prod_materials_so_item_fk')
                    ->references('id')
                    ->on($prefix.'sales_order_items')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable($prefix.'production_order_labor')) {
            Schema::create($prefix.'production_order_labor', function (Blueprint $table) use ($orders) {
                $table->id();
                $table->foreignId('production_order_id')->constrained($orders, 'id', 'acct_prod_labor_order_fk')->cascadeOnDelete();
                $table->foreignId('worker_id')->nullable()->constrained('users', 'id', 'acct_prod_labor_worker_fk')->nullOnDelete();
                $table->string('worker_name')->nullable();
                $table->string('description')->nullable();
                $table->string('rate_basis', 20)->default('hour');
                $table->decimal('quantity', 18, 4)->default(0);
                $table->decimal('rate', 18, 4)->default(0);
                $table->decimal('total_cost', 18, 4)->default(0);
                $table->date('work_date')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'acct_prod_labor_created_by_fk')->nullOnDelete();
                $table->timestamps();

                $table->index(['production_order_id', 'sort_order'], 'acct_prod_labor_order_sort_idx');
            });
        }

        if (! Schema::hasTable($prefix.'production_order_services')) {
            Schema::create($prefix.'production_order_services', function (Blueprint $table) use ($orders, $products) {
                $table->id();
                $table->foreignId('production_order_id')->constrained($orders, 'id', 'acct_prod_services_order_fk')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained($products, 'id', 'acct_prod_services_product_fk')->nullOnDelete();
                $table->string('description')->nullable();
                $table->string('cost_source', 20)->default('internal');
                $table->foreignId('service_provider_id')->nullable()->constrained('users', 'id', 'acct_prod_services_provider_fk')->nullOnDelete();
                $table->string('provider_reference')->nullable();
                $table->string('pricing_basis', 20)->default('quantity');
                $table->decimal('quantity', 18, 4)->default(0);
                $table->decimal('unit_cost', 18, 4)->default(0);
                $table->decimal('total_cost', 18, 4)->default(0);
                $table->date('sent_date')->nullable();
                $table->date('received_date')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'acct_prod_services_created_by_fk')->nullOnDelete();
                $table->timestamps();

                $table->index(['production_order_id', 'sort_order'], 'acct_prod_services_order_sort_idx');
                $table->index('service_provider_id', 'acct_prod_services_provider_idx');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        Schema::dropIfExists($prefix.'production_order_services');
        Schema::dropIfExists($prefix.'production_order_labor');
        Schema::dropIfExists($prefix.'production_order_materials');
    }
};
