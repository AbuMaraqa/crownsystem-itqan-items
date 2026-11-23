<?php

namespace Modules\Accounting\Support;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات إعدادات البيع: أنواع طلبيات البيع ومهلها.
 */
class SalesPermissions
{
    public const GROUPS = [
        'Sales Order Type Management' => [
            'Sales Order Type View List',
            'Sales Order Type Create',
            'Sales Order Type Update',
            'Sales Order Type Delete',
        ],
    ];

    public static function sync(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $hasModuleName = Schema::hasColumn('permissions', 'module_name');

        foreach (self::GROUPS as $moduleName => $names) {
            foreach ($names as $name) {
                Permission::firstOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    $hasModuleName ? ['module_name' => $moduleName] : []
                );

                $role->givePermissionTo($name);
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
