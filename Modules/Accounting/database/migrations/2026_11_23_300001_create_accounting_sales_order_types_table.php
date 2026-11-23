<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أنواع طلبيات البيع («دليل أنواع الطلبيات» من الشركة): لكل نوع مهلة قصوى بالأيام لتحضير الطلبية
 * وجاهزيتها للفوترة، تُحسب من تأكيد الطلبية. الطلبية تحفظ نوعها وموعد جاهزيتها (ready_by_date)
 * عند التأكيد، فتغيير المهلة لاحقاً لا يغيّر مواعيد الطلبيات السابقة.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: int}> الاسم، متى يُستخدم، المهلة بالأيام */
    private const GUIDE = [
        ['تحضير من المخزن', 'عندما تكون البضاعة موجودة عندنا.', 3],
        ['دهان إلى (متسبي)', 'عندما تحتاج الطلبية دهاناً من أي مدهنة.', 14],
        ['توصية من الوبين', 'توصية من مورد من الداخل.', 30],
        ['توصية من الصين', 'طلبية بيع مرتبطة بشراء من الصين.', 90],
        ['توصية من كليل', 'توصية من مورد من الداخل.', 30],
        ['توصية من مورد محلي', 'توصية من مورد محلي من الضفة.', 15],
        ['توصية قطع', 'توصية قطع من أي مورد.', 30],
    ];

    public function up(): void
    {
        $prefix = config('accounting.table_prefix');
        $types = $prefix.'sales_order_types';
        $orders = $prefix.'sales_orders';

        if (! Schema::hasTable($types)) {
            Schema::create($types, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('description')->nullable();
                $table->unsignedSmallInteger('lead_days');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();
            });

            $now = now();
            DB::table($types)->insert(array_map(fn (array $type, int $index): array => [
                'name' => $type[0], 'description' => $type[1], 'lead_days' => $type[2], 'is_active' => true,
                'sort_order' => $index + 1, 'created_at' => $now, 'updated_at' => $now,
            ], self::GUIDE, array_keys(self::GUIDE)));
        }

        if (Schema::hasTable($orders) && ! Schema::hasColumn($orders, 'order_type_id')) {
            Schema::table($orders, function (Blueprint $table) use ($types) {
                $table->unsignedBigInteger('order_type_id')->nullable()->after('direct_supplier_id');
                $table->date('ready_by_date')->nullable()->after('expected_delivery_date');
                $table->foreign('order_type_id', 'acct_so_order_type_fk')->references('id')->on($types)->nullOnDelete();
                $table->index('ready_by_date', 'acct_so_ready_by_idx');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        if (Schema::hasColumn($prefix.'sales_orders', 'order_type_id')) {
            Schema::table($prefix.'sales_orders', function (Blueprint $table) {
                $table->dropForeign('acct_so_order_type_fk');
                $table->dropIndex('acct_so_ready_by_idx');
                $table->dropColumn(['order_type_id', 'ready_by_date']);
            });
        }

        Schema::dropIfExists($prefix.'sales_order_types');
    }
};
