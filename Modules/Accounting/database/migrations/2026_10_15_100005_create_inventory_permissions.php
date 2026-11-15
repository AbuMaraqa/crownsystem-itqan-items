<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Accounting\Support\InventoryPermissions;

/**
 * صلاحيات المخازن تُنشأ مع الترحيل حتى تعمل الصفحات مباشرة بعد النشر دون تشغيل الـ seeders،
 * وتُمنح لدور Super Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        InventoryPermissions::sync();
    }

    public function down(): void
    {
        //
    }
};
