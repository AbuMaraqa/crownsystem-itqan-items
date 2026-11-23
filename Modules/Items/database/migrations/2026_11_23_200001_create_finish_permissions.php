<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Items\Support\ItemModelPermissions;

/**
 * صلاحيات صفحة «الألوان» (Finish)، للنسخ القائمة ايضاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        ItemModelPermissions::sync();
    }

    public function down(): void
    {
        //
    }
};
