<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'Shipping Company', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Insurance Company', 'guard_name' => 'web']);
    }

    public function down(): void
    {
        Role::where('name', 'Shipping Company')->where('guard_name', 'web')->delete();
        Role::where('name', 'Insurance Company')->where('guard_name', 'web')->delete();
    }
};
