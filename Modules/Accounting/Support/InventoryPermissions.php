<?php

namespace Modules\Accounting\Support;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class InventoryPermissions
{
    public const GROUPS = [
        'Warehouse Location Management' => [
            'Warehouse Location View List',
            'Warehouse Location Manage',
            'Shelf Label Print',
        ],
        'Stock Management' => [
            'Stock View List',
            'Stock Item Card View',
            'Stock Value View',
            'Stock Allow Negative',
        ],
        'Stock Follow-up Management' => [
            'Aluminum Stock Follow-up View List',
            'Parts Stock Follow-up View List',
            'Stock Limit Update',
        ],
        'Goods Receipt Management' => [
            'Goods Receipt View List',
            'Goods Receipt Create',
            'Goods Receipt Update',
            'Goods Receipt Delete',
            'Goods Receipt Post',
            'Goods Receipt Cancel',
        ],
        'Delivery Note Management' => [
            'Delivery Note View List',
            'Delivery Note Create',
            'Delivery Note Update',
            'Delivery Note Delete',
            'Delivery Note Post',
            'Delivery Note Cancel',
        ],
        'Stock Transfer Management' => [
            'Stock Transfer View List',
            'Stock Transfer Create',
            'Stock Transfer Update',
            'Stock Transfer Delete',
            'Stock Transfer Post',
            'Stock Transfer Cancel',
        ],
        'Stock Adjustment Management' => [
            'Stock Adjustment View List',
            'Stock Adjustment Create',
            'Stock Adjustment Update',
            'Stock Adjustment Delete',
            'Stock Adjustment Post',
            'Stock Adjustment Cancel',
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
