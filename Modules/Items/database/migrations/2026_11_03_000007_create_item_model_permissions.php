<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Items\Support\ItemModelPermissions;

/**
 * صلاحيات انواع الاصناف وقوائم الاسعار ووحدات الصنف واسعاره، للنسخ القائمة ايضاً.
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
