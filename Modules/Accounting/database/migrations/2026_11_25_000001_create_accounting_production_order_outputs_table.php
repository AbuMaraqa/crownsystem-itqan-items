<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الاصناف المنتجة في أمر الإنتاج (تبويب «الإنتاج» في اتقان): عند الترحيل تخرج الاصناف المدخلة
 * من المخزن، ويدخل كل صنف منتج بنصيبه من التكلفة الكلية (الاصناف + الأجور + الخدمات).
 * ومعها قيد الترحيل على الأمر.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');
        $orders = $prefix.'production_orders';
        $outputs = $prefix.'production_order_outputs';

        if (! Schema::hasTable($outputs)) {
            Schema::create($outputs, function (Blueprint $table) use ($orders) {
                $table->id();
                $table->foreignId('production_order_id')->constrained($orders, 'id', 'acct_prod_outputs_order_fk')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained(config('items.table_prefix').'products', 'id', 'acct_prod_outputs_product_fk')->nullOnDelete();
                $table->string('description')->nullable();
                $table->string('pricing_type', 20)->default('simple');
                $table->decimal('quantity', 18, 4)->default(0);
                $table->decimal('weight', 18, 4)->default(0);
                $table->decimal('unit_cost', 20, 6)->default(0);
                $table->decimal('total_cost', 18, 4)->default(0);
                $table->text('notes')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'acct_prod_outputs_created_by_fk')->nullOnDelete();
                $table->timestamps();

                $table->index(['production_order_id', 'sort_order'], 'acct_prod_outputs_order_sort_idx');
            });
        }

        if (! Schema::hasColumn($orders, 'journal_entry_id')) {
            Schema::table($orders, function (Blueprint $table) use ($prefix) {
                $table->foreignId('journal_entry_id')->nullable()->after('sales_amount')
                    ->constrained($prefix.'journal_entries', 'id', 'acct_prod_orders_journal_fk')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        if (Schema::hasColumn($prefix.'production_orders', 'journal_entry_id')) {
            Schema::table($prefix.'production_orders', function (Blueprint $table) {
                $table->dropForeign('acct_prod_orders_journal_fk');
                $table->dropColumn('journal_entry_id');
            });
        }

        Schema::dropIfExists($prefix.'production_order_outputs');
    }
};
