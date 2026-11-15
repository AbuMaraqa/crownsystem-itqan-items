<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Accounting\Support\LedgerPermissions;

/**
 * صلاحيات الالغاء واقفال الفترات وقيد اقفال السنة وتخصيص الدفعات تُنشأ مع الترحيل
 * وتُمنح لدور Super Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        LedgerPermissions::sync();
    }

    public function down(): void
    {
        //
    }
};
