<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حسابا الايراد والتكلفة الخاصان بالصنف (اختياريان). بلا قيمة يُستخدم الحساب
 * العام من ربط الحسابات حسب طبيعة الصنف (سلعة، خدمة، خدمة نقل).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('items.table_prefix').'products', function (Blueprint $table) {
            $table->foreignId('revenue_account_id')
                ->nullable()
                ->after('service_cost_source')
                ->constrained(config('accounting.table_prefix').'accounts')
                ->nullOnDelete();
            $table->foreignId('cost_account_id')
                ->nullable()
                ->after('revenue_account_id')
                ->constrained(config('accounting.table_prefix').'accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table(config('items.table_prefix').'products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_account_id');
            $table->dropConstrainedForeignId('revenue_account_id');
        });
    }
};
