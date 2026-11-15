<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Accounting\Services\StockAlertService;
use Modules\Accounting\Support\InventoryPermissions;

/**
 * صلاحيات متابعة المخزون وتعديل الحدود، ثم حساب حالة كل صنف مراقَب مرة اولى
 * بلا اشعارات، حتى يظهر قسم المتابعة مكتملاً مباشرة بعد النشر.
 */
return new class extends Migration
{
    public function up(): void
    {
        InventoryPermissions::sync();

        app(StockAlertService::class)->rebuild(notify: false);
    }

    public function down(): void
    {
        //
    }
};
