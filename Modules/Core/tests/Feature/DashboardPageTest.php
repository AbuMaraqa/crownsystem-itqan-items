<?php

namespace Modules\Core\tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Modules\Core\Enums\UserRole;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * لوحة البداية بتصميم malbo (Inertia + Vue): كل ودجت بصلاحيتها فقط، ومحدد باقة الوحدات
 * للمدير الاعلى وحده.
 */
class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
    }

    private function userWith(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_the_dashboard_is_the_inertia_home_page_with_the_shared_shell(): void
    {
        $this->actingAs($this->userWith([]))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Core/Home/Index')
                ->has('auth.user')
                ->has('sidebar')
                ->has('translations')
                ->has('ziggy')
                ->has('locales')
                ->where('dir', fn ($dir) => in_array($dir, ['rtl', 'ltr'], true))
                ->etc());
    }

    public function test_widgets_without_permission_are_not_sent(): void
    {
        $this->actingAs($this->userWith([]))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics', [])
                ->where('alerts', [])
                ->where('quickLinks', [])
                ->where('ordersPerMonth', null)
                ->where('usersByRole', null)
                ->where('recentOrders', null)
                ->etc());
    }

    public function test_permitted_widgets_carry_twelve_months_and_counts(): void
    {
        $this->withoutExceptionHandling();

        $this->actingAs($this->userWith(['User View List', 'Order View List']))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('ordersPerMonth.categories', 12)
                ->has('ordersPerMonth.data', 12)
                ->has('newUsersPerMonth.data', 12)
                ->has('usersByRole.labels')
                ->has('recentOrders.rows', 0)
                ->where('metrics.0.key', 'users')
                ->where('metrics.0.value', 1)
                ->where('alerts.0.key', 'pending_orders')
                ->where('alerts.0.value', 0)
                ->etc());
    }

    public function test_the_purchase_order_calendar_needs_its_permission(): void
    {
        $this->actingAs($this->userWith([]))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('purchaseOrderCalendar', null)->etc());

        $this->getJson(route('dashboard.purchase-order-events', ['start' => '2026-11-01', 'end' => '2026-12-01']))
            ->assertForbidden();
    }

    public function test_the_purchase_order_calendar_sends_its_types_and_events(): void
    {
        $this->actingAs($this->userWith(['Consolidated Purchase Order View List']))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrderCalendar.url', route('dashboard.purchase-order-events'))
                ->has('purchaseOrderCalendar.types', 7)
                ->etc());

        $this->getJson(route('dashboard.purchase-order-events', ['start' => '2026-11-01', 'end' => '2026-12-01']))
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->getJson(route('dashboard.purchase-order-events', ['start' => '2026-12-01', 'end' => '2026-11-01']))
            ->assertUnprocessable();
    }

    public function test_the_module_selector_is_for_the_super_admin_only(): void
    {
        $this->actingAs($this->userWith([]))->get(route('module-selector'))->assertForbidden();
        $this->post(route('module-selector.select'), ['package' => 'core_store'])->assertForbidden();

        $admin = $this->userWith([]);
        $admin->assignRole(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value, 'guard_name' => 'web']));

        $this->actingAs($admin)
            ->get(route('module-selector'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Core/ModuleSelector/Index')
                ->where('packages.0.key', 'core_store')
                ->has('packages.0.modules')
                ->etc());

        // باقة غير معروفة ترفض قبل تعطيل اي وحدة.
        $this->post(route('module-selector.select'), ['package' => 'unknown'])->assertSessionHasErrors('package');
    }

    public function test_an_inertia_visit_to_a_page_stays_inertia(): void
    {
        $url = route('users.index');
        $version = app(HandleInertiaRequests::class)->version(Request::create($url));

        $this->actingAs($this->userWith(['User View List']))
            ->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true');
    }
}
