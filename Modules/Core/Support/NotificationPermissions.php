<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحية صفحة الاشعارات. الاشعارات شخصية (كل مستخدم يرى اشعاراته فقط)،
 * لذلك تمنح لكل الادوار الموجودة وليس لـ Super Admin وحده.
 */
class NotificationPermissions
{
    public const GROUPS = [
        'Notification Management' => [
            'Notification View List',
        ],
    ];

    public static function sync(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $roles = Role::query()->where('guard_name', 'web')->get();
        $hasModuleName = Schema::hasColumn('permissions', 'module_name');

        foreach (self::GROUPS as $moduleName => $names) {
            foreach ($names as $name) {
                Permission::firstOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    $hasModuleName ? ['module_name' => $moduleName] : []
                );

                foreach ($roles as $role) {
                    $role->givePermissionTo($name);
                }
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
