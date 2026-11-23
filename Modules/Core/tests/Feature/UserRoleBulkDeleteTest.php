<?php

namespace Modules\Core\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * الحذف الجماعي في جدولي المستخدمين والادوار: يُحذف المحدد بقواعد الحذف المفرد نفسها؛ الحساب الحالي
 * ومدير النظام (لغير مدير النظام) وادوار النظام والادوار الممنوحة لمستخدمين تبقى مع ذكر السبب.
 */
class UserRoleBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'User View List', 'User Delete',
        'Roles And Permissions View List', 'Roles And Permissions Delete',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(self::PERMISSIONS);
        $this->actingAs($this->admin);
    }

    private function role(string $name): Role
    {
        return Role::findOrCreate($name, 'web');
    }

    public function test_the_selected_users_are_deleted(): void
    {
        [$first, $second, $kept] = User::factory()->count(3)->create()->all();

        $this->delete(route('users.destroy-many'), ['ids' => [$first->id, $second->id]])
            ->assertRedirect()
            ->assertSessionHas('success', __('The selected rows were deleted (:count).', ['count' => 2]));

        $this->assertNull(User::find($first->id));
        $this->assertNull(User::find($second->id));
        $this->assertNotNull(User::find($kept->id));
    }

    public function test_the_signed_in_user_and_a_super_admin_stay(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole($this->role('Super Admin'));
        $employee = User::factory()->create();

        $this->delete(route('users.destroy-many'), ['ids' => [$employee->id, $this->admin->id, $owner->id]])
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, __('You cannot delete your own account.'))
                && str_contains($message, __('Only a Super Admin can delete a Super Admin.')));

        $this->assertNull(User::find($employee->id));
        $this->assertNotNull($this->admin->fresh());
        $this->assertNotNull($owner->fresh());

        $this->delete(route('users.destroy-many'), ['ids' => [$owner->id]])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, __('Only a Super Admin can delete a Super Admin.')));

        $this->assertNotNull($owner->fresh());
    }

    public function test_free_roles_are_deleted_and_system_or_given_roles_stay(): void
    {
        $free = $this->role('Storekeeper');
        $system = $this->role('Customer');
        $given = $this->role('Accountant');
        User::factory()->create()->assignRole($given);

        $this->delete(route('roles.destroy-many'), ['ids' => [$free->id, $system->id, $given->id]])
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, __('This role is used by the system and cannot be deleted.'))
                && str_contains($message, __('This role is given to users. Move them to another role first.')));

        $this->assertNull(Role::find($free->id));
        $this->assertNotNull(Role::find($system->id));
        $this->assertNotNull(Role::find($given->id));

        $other = $this->role('Driver');

        $this->delete(route('roles.destroy-many'), ['ids' => [$other->id]])
            ->assertSessionHas('success', __('The selected rows were deleted (:count).', ['count' => 1]));

        $this->assertNull(Role::find($other->id));
    }

    public function test_bulk_delete_needs_the_delete_permission(): void
    {
        $user = User::factory()->create();
        $role = $this->role('Storekeeper');

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['User View List', 'Roles And Permissions View List']);
        $this->actingAs($viewer);

        $this->delete(route('users.destroy-many'), ['ids' => [$user->id]])->assertForbidden();
        $this->delete(route('roles.destroy-many'), ['ids' => [$role->id]])->assertForbidden();

        $this->assertNotNull($user->fresh());
        $this->assertNotNull(Role::find($role->id));
    }
}
