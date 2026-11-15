<?php

namespace Modules\Accounting\Support;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات دورة الشراء الحديثة: عرض سعر الشراء بين طلبية الشراء وفاتورة الشراء.
 */
class PurchasingPermissions
{
    public const GROUPS = [
        'Purchase Quotation Management' => [
            'Purchase Quotation View List',
            'Purchase Quotation Create',
            'Purchase Quotation Update',
            'Purchase Quotation Delete',
            'Purchase Quotation Approve',
            'Purchase Quotation Cancel',
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
