<?php

namespace Modules\Core\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\AdditionalCostType;
use Modules\Core\Entities\AppVersion;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Core\Settings\GeneralSettings;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * صفحة الاعدادات العامة في لوحة Vue: بيانات الموقع، العملة الاساسية، لون اللوحة،
 * اصدارات التطبيق، وبنود التكاليف الاضافية.
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        foreach (['Setting View List', 'Setting Update'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['Setting View List', 'Setting Update']);
        $this->actingAs($this->admin);

        $this->currency = Currency::query()->create([
            'name' => 'شيكل', 'code' => 'ILS', 'symbol' => '₪', 'symbol_position' => 'after',
            'decimal_places' => 2, 'exchange_rate' => 1, 'is_active' => true, 'is_default' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'site_name' => 'كراون',
            'email_address_for_contact' => 'info@example.com',
            'site_description' => 'نظام محاسبة',
            'base_currency_id' => $this->currency->id,
            'weight_variance_tolerance' => 7.5,
            'dashboard_color' => '#c2410c',
            'dashboard_font' => 'cairo',
            'app_versions' => [],
            'additional_cost_types' => [],
            ...$overrides,
        ];
    }

    public function test_the_page_shows_the_current_settings(): void
    {
        $this->get(route('settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Core/Settings/Index')
                ->where('settings.base_currency_id', $this->currency->id)
                ->where('currencyOptions.0.label', 'ILS')
                ->has('colorPresets')
                ->has('fonts', 3));
    }

    public function test_settings_are_saved(): void
    {
        $this->put(route('settings.update'), $this->payload())->assertRedirect(route('settings'));

        $settings = app(GeneralSettings::class)->refresh();
        $this->assertSame('كراون', $settings->site_name);
        $this->assertSame($this->currency->id, $settings->base_currency_id);
        $this->assertEquals(7.5, $settings->weight_variance_tolerance);
        $this->assertSame('#C2410C', $settings->dashboard_color);
        $this->assertSame('cairo', $settings->dashboard_font);
    }

    public function test_a_color_too_light_for_white_text_is_refused(): void
    {
        $this->put(route('settings.update'), $this->payload(['dashboard_color' => '#EE7517']))->assertSessionHasErrors('dashboard_color');
    }

    public function test_app_versions_and_cost_items_follow_the_form(): void
    {
        AppVersion::query()->create(['platform' => 'ios', 'min_version' => '1.0', 'latest_version' => '1.0', 'store_url' => 'https://example.com/ios']);
        $kept = AdditionalCostType::query()->create(['name' => 'جمارك', 'is_active' => true, 'created_by' => $this->admin->id]);
        $removed = AdditionalCostType::query()->create(['name' => 'تخزين', 'is_active' => true, 'created_by' => $this->admin->id]);

        $this->put(route('settings.update'), $this->payload([
            'app_versions' => [
                ['platform' => 'android', 'store_url' => 'https://example.com/android', 'min_version' => '2.0', 'latest_version' => '2.1', 'maintenance_mode' => false, 'message' => null],
            ],
            'additional_cost_types' => [
                ['id' => $kept->id, 'name' => 'جمارك وضرائب', 'is_active' => false],
                ['id' => null, 'name' => 'شحن', 'is_active' => true],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['android'], AppVersion::query()->pluck('platform')->all());
        $this->assertSame('جمارك وضرائب', $kept->fresh()->name);
        $this->assertFalse((bool) $kept->fresh()->is_active);
        $this->assertSoftDeleted($removed);
        $this->assertSame($this->admin->id, AdditionalCostType::query()->where('name', 'شحن')->value('created_by'));
    }

    public function test_saving_needs_the_update_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('Setting View List');

        $this->actingAs($viewer)->put(route('settings.update'), $this->payload())->assertForbidden();
    }
}
