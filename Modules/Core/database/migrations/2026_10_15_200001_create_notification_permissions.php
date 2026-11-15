<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Core\Support\NotificationPermissions;

/**
 * صلاحية صفحة الاشعارات تُنشأ مع الترحيل وتمنح لكل الادوار الموجودة،
 * حتى يرى كل مستخدم اشعاراته مباشرة بعد النشر.
 */
return new class extends Migration
{
    public function up(): void
    {
        NotificationPermissions::sync();
    }

    public function down(): void
    {
        //
    }
};
