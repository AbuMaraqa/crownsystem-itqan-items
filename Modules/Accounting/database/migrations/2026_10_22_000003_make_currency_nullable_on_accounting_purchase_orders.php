<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلبية الشراء صارت بلا اسعار (السعر في عرض سعر الشراء او فاتورة الشراء)، فلم تعد لها
 * عملة. الطلبيات القديمة تحتفظ بعملتها واسعارها.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('accounting.table_prefix').'purchase_orders';

        if (Schema::hasColumn($tableName, 'currency_id')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('currency_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        //
    }
};
