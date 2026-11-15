<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - warehouses.inventory_account_id: حساب مخزون المستودع في شجرة الحسابات (فرعي تحت «المخزون»)،
 *   فتظهر قيمة كل مخزن في الميزانية وكشف الحساب.
 * - purchase_orders.delivery_type: توريد للمخزن او شحن مباشر للزبون (لا يدخل المخزن)،
 *   مع الزبون وطلبية البيع التي يُشحن لها.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');

        if (! Schema::hasColumn($prefix.'warehouses', 'inventory_account_id')) {
            Schema::table($prefix.'warehouses', function (Blueprint $table) use ($prefix) {
                $table->foreignId('inventory_account_id')
                    ->nullable()
                    ->after('branch_id')
                    ->constrained($prefix.'accounts', 'id', 'acct_warehouses_inventory_account_fk')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn($prefix.'purchase_orders', 'delivery_type')) {
            Schema::table($prefix.'purchase_orders', function (Blueprint $table) use ($prefix) {
                $table->string('delivery_type', 20)->default('warehouse')->after('warehouse_id');
                $table->foreignId('direct_customer_id')
                    ->nullable()
                    ->after('delivery_type')
                    ->constrained('users', 'id', 'acct_po_direct_customer_fk')
                    ->nullOnDelete();
                $table->foreignId('direct_sales_order_id')
                    ->nullable()
                    ->after('direct_customer_id')
                    ->constrained($prefix.'sales_orders', 'id', 'acct_po_direct_sales_order_fk')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        if (Schema::hasColumn($prefix.'purchase_orders', 'delivery_type')) {
            Schema::table($prefix.'purchase_orders', function (Blueprint $table) {
                $table->dropForeign('acct_po_direct_customer_fk');
                $table->dropForeign('acct_po_direct_sales_order_fk');
                $table->dropColumn(['delivery_type', 'direct_customer_id', 'direct_sales_order_id']);
            });
        }

        if (Schema::hasColumn($prefix.'warehouses', 'inventory_account_id')) {
            Schema::table($prefix.'warehouses', function (Blueprint $table) {
                $table->dropForeign('acct_warehouses_inventory_account_fk');
                $table->dropColumn('inventory_account_id');
            });
        }
    }
};
