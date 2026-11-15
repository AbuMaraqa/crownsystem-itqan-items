<?php

namespace Modules\Core\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * صفحة الادوار والصلاحيات في لوحة Vue.
 */
class RolePagesTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['Roles And Permissions View List', 'Roles And Permissions Create', 'Roles And Permissions Update', 'Roles And Permissions Delete'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        foreach ([...self::ALL, 'Warehouse View List', 'Sales Invoice Post'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $admin = User::factory()->create();
        $admin->givePermissionTo(self::ALL);
        $this->actingAs($admin);
    }

    public function test_the_page_groups_permissions_by_feature_in_arabic(): void
    {
        Role::findOrCreate('Accountant', 'web')->givePermissionTo('Sales Invoice Post');

        $this->get(route('roles.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Core/Roles/Index')
                ->where('table.data.0.name', 'Accountant')
                ->where('table.data.0.summary.groups.0.label', 'فواتير المبيعات')
                ->where('table.data.0.summary.groups.0.actions.0', 'ترحيل')
                ->where('permissionGroups', fn ($groups) => collect($groups)->pluck('label')->contains('المخازن')));
    }

    public function test_a_role_is_created_and_updated_with_its_permissions(): void
    {
        $warehouse = Permission::findByName('Warehouse View List', 'web');
        $post = Permission::findByName('Sales Invoice Post', 'web');

        $this->post(route('roles.store'), ['name' => 'Storekeeper', 'permissions' => [$warehouse->id]])->assertSessionHas('success');

        $role = Role::findByName('Storekeeper', 'web');
        $this->assertTrue($role->hasPermissionTo('Warehouse View List'));

        $this->put(route('roles.update', $role->id), ['name' => 'Store Keeper', 'permissions' => [$post->id]])->assertSessionHas('success');

        $role->refresh();
        $this->assertSame('Store Keeper', $role->name);
        $this->assertFalse($role->hasPermissionTo('Warehouse View List'));
        $this->assertTrue($role->hasPermissionTo('Sales Invoice Post'));
    }

    public function test_system_roles_are_not_renamed_or_deleted(): void
    {
        foreach (['Customer', 'Supplier'] as $name) {
            $role = Role::findOrCreate($name, 'web');

            $this->put(route('roles.update', $role->id), ['name' => $name.' X', 'permissions' => []])->assertSessionHasErrors('name');
            $this->delete(route('roles.destroy', $role->id))->assertSessionHas('error');
            $this->assertNotNull(Role::find($role->id));
        }
    }

    public function test_the_super_admin_role_keeps_every_permission(): void
    {
        $role = Role::findOrCreate('Super Admin', 'web');

        $this->put(route('roles.update', $role->id), ['name' => 'Super Admin', 'permissions' => []])->assertSessionHasErrors('permissions');
    }

    public function test_a_role_given_to_users_is_not_deleted(): void
    {
        $role = Role::findOrCreate('Cashier', 'web');
        User::factory()->create()->assignRole($role);

        $this->delete(route('roles.destroy', $role->id))->assertSessionHas('error');
        $this->assertNotNull(Role::find($role->id));
    }

    public function test_an_unused_role_is_deleted(): void
    {
        $role = Role::findOrCreate('Temporary', 'web');

        $this->delete(route('roles.destroy', $role->id))->assertSessionHas('success');
        $this->assertNull(Role::find($role->id));
    }

    public function test_changes_need_their_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('Roles And Permissions View List');
        $role = Role::findOrCreate('Temporary', 'web');

        $this->actingAs($viewer);
        $this->get(route('roles.index'))->assertOk();
        $this->post(route('roles.store'), ['name' => 'X'])->assertForbidden();
        $this->put(route('roles.update', $role->id), ['name' => 'Y'])->assertForbidden();
        $this->delete(route('roles.destroy', $role->id))->assertForbidden();
    }
}
