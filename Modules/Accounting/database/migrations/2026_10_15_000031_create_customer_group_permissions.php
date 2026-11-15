<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات تصنيفات الزبائن لقواعد البيانات القائمة (المثبتة الجديدة تأخذها من PermissionSeeder).
 */
return new class extends Migration
{
    private const MODULE = 'Customer Group Management';

    private const PERMISSIONS = [
        'Customer Group View List',
        'Customer Group Create',
        'Customer Group Update',
        'Customer Group Delete',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::query()->where('name', 'Super Admin')->where('guard_name', 'web')->first();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['module_name' => self::MODULE]
            );

            $role?->givePermissionTo($name);
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
    }
};
