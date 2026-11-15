<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلبية البيع التي يوردها مورد آخر مباشرة للزبون:
 * - fulfillment_type: من مخزوننا (warehouse) او توريد مباشر من المورد (direct).
 * - direct_supplier_id: المورد المقترح، يملأ طلبية الشراء التي ينشئها قسم المشتريات.
 * - purchasing_notified_at: وقت اشعار قسم المشتريات، حتى لا يتكرر الاشعار.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix');

        if (! Schema::hasColumn($prefix.'sales_orders', 'fulfillment_type')) {
            Schema::table($prefix.'sales_orders', function (Blueprint $table) {
                $table->string('fulfillment_type', 20)->default('warehouse')->after('warehouse_id');
                $table->foreignId('direct_supplier_id')
                    ->nullable()
                    ->after('fulfillment_type')
                    ->constrained('users', 'id', 'acct_so_direct_supplier_fk')
                    ->nullOnDelete();
                $table->timestamp('purchasing_notified_at')->nullable()->after('direct_supplier_id');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix');

        if (Schema::hasColumn($prefix.'sales_orders', 'fulfillment_type')) {
            Schema::table($prefix.'sales_orders', function (Blueprint $table) {
                $table->dropForeign('acct_so_direct_supplier_fk');
                $table->dropColumn(['fulfillment_type', 'direct_supplier_id', 'purchasing_notified_at']);
            });
        }
    }
};
