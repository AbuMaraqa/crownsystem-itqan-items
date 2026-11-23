<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سطر الخدمة في أمر الإنتاج يحفظ سطر طلبية البيع الذي جاء منه (كسطر المادة)، فتُحسب قيمة البيع
 * لكل أمر من أسطره هو، لا من الطلبية كلها في كل أمر.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');
        $table = $prefix.'production_order_services';

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'sales_order_item_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($prefix) {
            $blueprint->unsignedBigInteger('sales_order_item_id')->nullable()->after('production_order_id');
            $blueprint->foreign('sales_order_item_id', 'acct_prod_services_so_item_fk')
                ->references('id')
                ->on($prefix.'sales_order_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $table = config('accounting.table_prefix').'production_order_services';

        if (Schema::hasColumn($table, 'sales_order_item_id')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign('acct_prod_services_so_item_fk');
                $blueprint->dropColumn('sales_order_item_id');
            });
        }
    }
};
