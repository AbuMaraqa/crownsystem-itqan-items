<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * صلاحيات قسم الإنتاج لقواعد البيانات القائمة (المثبتة الجديدة تأخذها من PermissionSeeder).
 */
return new class extends Migration
{
    private const MODULE = 'Production Order Management';

    private const PERMISSIONS = [
        'Production Order View List',
        'Production Order Create',
        'Production Order Update',
        'Production Order Delete',
        'Production Order View',
        'Production Order Start',
        'Production Order Complete',
        'Production Order Cancel',
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
