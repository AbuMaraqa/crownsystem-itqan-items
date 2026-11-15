<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'Clearance Company', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Delivery Company', 'guard_name' => 'web']);
    }

    public function down(): void
    {
        Role::where('name', 'Clearance Company')->where('guard_name', 'web')->delete();
        Role::where('name', 'Delivery Company')->where('guard_name', 'web')->delete();
    }
};
