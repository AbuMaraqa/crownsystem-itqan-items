<?php

namespace Modules\Accounting\Support;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات دورة الاستاذ العام: الغاء المستندات المرحّلة، اقفال الفترات الشهرية،
 * قيد اقفال السنة، وتخصيص الدفعات على الفواتير.
 */
class LedgerPermissions
{
    public const GROUPS = [
        'Sales Invoice Management' => [
            'Sales Invoice Cancel',
        ],
        'Purchase Invoice Management' => [
            'Purchase Invoice Cancel',
        ],
        'Sales Return Management' => [
            'Sales Return Cancel',
        ],
        'Purchase Return Management' => [
            'Purchase Return Cancel',
        ],
        'Receipt Voucher Management' => [
            'Receipt Voucher Cancel',
        ],
        'Payment Voucher Management' => [
            'Payment Voucher Cancel',
        ],
        'Accounting Period Management' => [
            'Accounting Period Close',
            'Accounting Period Reopen',
        ],
        'Period Closing Voucher Management' => [
            'Period Closing Voucher View List',
            'Period Closing Voucher Cancel',
        ],
        'Payment Allocation Management' => [
            'Payment Allocation Create',
            'Payment Allocation Delete',
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
