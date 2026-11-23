<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Accounting\Support\SalesPermissions;

/**
 * صلاحيات صفحة أنواع طلبيات البيع، للنسخ القائمة ايضاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        SalesPermissions::sync();
    }

    public function down(): void
    {
        //
    }
};
