<?php

namespace Modules\Items\Support;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات انواع الاصناف وقوائم الاسعار، وصفحة وحدات الصنف وباركوداته واسعاره، وقائمة الألوان.
 */
class ItemModelPermissions
{
    public const UNITS_AND_PRICES = 'Product Units And Prices';

    public const GROUPS = [
        'Item Type Profile Management' => [
            'Item Type Profile View List',
            'Item Type Profile Create',
            'Item Type Profile Update',
            'Item Type Profile Delete',
            'Item Type Profile View',
            'Item Type Profile Info',
        ],
        'Price List Management' => [
            'Price List View List',
            'Price List Create',
            'Price List Update',
            'Price List Delete',
            'Price List View',
            'Price List Info',
        ],
        'Product Management' => [
            self::UNITS_AND_PRICES,
        ],
        'Finish Management' => [
            'Finish View List',
            'Finish Create',
            'Finish Update',
            'Finish Delete',
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
