<?php

namespace Modules\Core\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * صفحات المستخدمين في لوحة Vue (الاضافة والتعديل والحذف)، واضافة مورد بلا بريد الكتروني.
 */
class UserAndSupplierFormsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $names = ['User View List', 'User Create', 'User Update', 'User Delete', 'Supplier Create'];
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo($names);
        $this->actingAs($this->admin);
    }

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'موظف جديد',
            'email' => 'new.employee@example.com',
            'phone' => null,
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'roles' => [$this->role('Employee')->id],
            'branch_id' => null,
            ...$overrides,
        ];
    }

    public function test_the_pages_are_inertia_pages(): void
    {
        $this->get(route('users.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Core/Users/Index')->has('table.data'));
        $this->get(route('users.add'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Core/Users/Add')->has('roleOptions'));
    }

    public function test_a_user_is_added_with_a_confirmed_password(): void
    {
        $this->post(route('users.store'), $this->payload(['email' => 'New.Employee@Example.com']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.index'));

        $user = User::where('email', 'new.employee@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('secret-pass', $user->password));
        $this->assertTrue($user->hasRole('Employee'));
    }

    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        $this->post(route('users.store'), $this->payload(['email' => 'mismatch@example.com', 'password_confirmation' => 'other-pass']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.com']);
    }

    public function test_editing_without_a_password_keeps_the_current_one(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);
        $user->assignRole($this->role('Employee'));

        $this->get(route('users.edit', $user->id))->assertInertia(fn (Assert $page) => $page->where('user.name', $user->name));

        $this->put(route('users.update', $user->id), $this->payload([
            'name' => 'اسم معدل',
            'email' => $user->email,
            'password' => '',
            'password_confirmation' => '',
        ]))->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('اسم معدل', $user->name);
        $this->assertTrue(Hash::check('old-password', $user->password));
    }

    public function test_customer_pricing_is_saved_only_for_a_customer(): void
    {
        $this->post(route('users.store'), $this->payload([
            'email' => 'customer@example.com',
            'roles' => [$this->role('Customer')->id],
            'profit_margin' => 12.5,
            'use_last_price' => 1,
        ]))->assertSessionHasNoErrors();

        $customer = User::where('email', 'customer@example.com')->firstOrFail();
        $this->assertEquals(12.5, (float) $customer->profit_margin);
        $this->assertTrue($customer->use_last_price);

        $this->post(route('users.store'), $this->payload([
            'email' => 'staff@example.com',
            'profit_margin' => 30,
            'use_last_price' => 1,
        ]))->assertSessionHasNoErrors();

        $staff = User::where('email', 'staff@example.com')->firstOrFail();
        $this->assertNull($staff->profit_margin);
        $this->assertNull($staff->use_last_price);
    }

    public function test_only_a_super_admin_gives_the_super_admin_role(): void
    {
        $this->post(route('users.store'), $this->payload(['roles' => [$this->role('Super Admin')->id]]))
            ->assertSessionHasErrors('roles');

        $this->assertDatabaseMissing('users', ['email' => 'new.employee@example.com']);
    }

    public function test_a_super_admin_is_managed_only_by_a_super_admin(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole($this->role('Super Admin'));

        $this->get(route('users.edit', $owner->id))->assertForbidden();
        $this->delete(route('users.destroy', $owner->id))->assertForbidden();
    }

    public function test_a_user_cannot_delete_their_own_account(): void
    {
        $this->delete(route('users.destroy', $this->admin->id))->assertSessionHas('error');
        $this->assertNotNull($this->admin->fresh());
    }

    public function test_a_user_is_deleted(): void
    {
        $user = User::factory()->create();

        $this->delete(route('users.destroy', $user->id))->assertSessionHas('success');
        $this->assertNull(User::find($user->id));
    }

    public function test_a_supplier_is_added_without_an_email(): void
    {
        $this->post(route('accounting.suppliers.store'), ['name' => 'باسل عبيدو', 'email' => ''])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('accounting.suppliers.index'));

        $supplier = User::where('name', 'باسل عبيدو')->firstOrFail();
        $this->assertNull($supplier->email);
        $this->assertTrue($supplier->hasRole('Supplier'));
    }
}
