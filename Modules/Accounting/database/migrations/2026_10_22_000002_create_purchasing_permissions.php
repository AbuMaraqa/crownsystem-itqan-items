<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Accounting\Support\PurchasingPermissions;

/**
 * صلاحيات عرض سعر الشراء تُنشأ مع الترحيل وتُمنح لدور Super Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        PurchasingPermissions::sync();
    }

    public function down(): void
    {
        //
    }
};
