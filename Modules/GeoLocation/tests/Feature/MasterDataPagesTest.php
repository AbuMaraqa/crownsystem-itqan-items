<?php

namespace Modules\GeoLocation\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\GeoLocation\Entities\City;
use Modules\GeoLocation\Entities\Country;
use Modules\GeoLocation\Entities\District;
use Modules\GeoLocation\Entities\Governorate;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * صفحات المواقع الجغرافية والفروع في لوحة Vue: الاضافة والتعديل يحفظان السجل نفسه (لا عملة ولا
 * سجلاً آخر)، والحذف يرفض موقعاً مستخدماً.
 */
class MasterDataPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        $permissions = [];
        foreach (['Country', 'Governorate', 'City', 'District', 'Branch'] as $entity) {
            foreach (['View List', 'Create', 'Update', 'Delete'] as $action) {
                $permissions[] = "$entity $action";
            }
        }

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        $this->actingAs($user);
    }

    public function test_the_lists_are_inertia_pages(): void
    {
        $this->city();

        foreach (['countries' => 'Country', 'governorates' => 'Governorate', 'cities' => 'City', 'districts' => 'District'] as $route => $page) {
            $this->get(route("$route.index"))
                ->assertOk()
                ->assertInertia(fn (Assert $inertia) => $inertia->component("GeoLocation/$page/Index")->has('table.data'));
        }

        $this->get(route('branches.index'))->assertOk()->assertInertia(fn (Assert $inertia) => $inertia->component('Branch/Branch/Index'));
    }

    public function test_country_edit_updates_the_country(): void
    {
        $country = $this->country();

        $this->put(route('countries.update', $country->id), [
            'name' => 'فلسطين', 'code' => 'PS', 'phone_code' => '972', 'currency_id' => $this->currency()->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Country::query()->count());
        $this->assertSame('972', (string) $country->refresh()->phone_code);
    }

    public function test_country_add_creates_a_country_not_a_currency(): void
    {
        $currency = $this->currency();

        $this->post(route('countries.store'), [
            'name' => 'الأردن', 'code' => 'JO', 'phone_code' => '962', 'currency_id' => $currency->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Currency::query()->count());
        $jordan = Country::query()->where('code', 'JO')->firstOrFail();
        $this->assertSame($currency->id, (int) $jordan->currency_id);
        $this->assertSame('الأردن', $jordan->translate('ar')->name);
    }

    public function test_governorate_add_and_edit_use_the_governorate_itself(): void
    {
        $country = $this->country();

        $this->post(route('governorates.store'), ['name' => 'الخليل', 'code' => 'HBN', 'country_id' => $country->id])->assertSessionHasNoErrors();

        $governorate = Governorate::query()->where('code', 'HBN')->firstOrFail();

        $this->put(route('governorates.update', $governorate->id), ['name' => 'محافظة الخليل', 'code' => 'HBN', 'country_id' => $country->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Governorate::query()->count());
        $this->assertSame('محافظة الخليل', $governorate->refresh()->translate('ar')->name);
        $this->assertSame(1, Currency::query()->count());
    }

    public function test_city_edit_updates_the_city(): void
    {
        $city = $this->city();

        $this->put(route('cities.update', $city->id), [
            'name' => 'الخليل', 'governorate_id' => $city->governorate_id, 'population' => 2000,
            'latitude' => 31.5, 'longitude' => 35.1, 'type' => 1, 'is_capital' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, City::query()->count());
        $this->assertSame(2000, (int) $city->refresh()->population);
    }

    public function test_district_add_and_edit_use_the_district_itself(): void
    {
        $city = $this->city();

        $this->post(route('districts.store'), ['name' => 'الحي الشرقي', 'city_id' => $city->id, 'type' => 1])->assertSessionHasNoErrors();

        $district = District::query()->where('city_id', $city->id)->firstOrFail();

        $this->put(route('districts.update', $district->id), ['name' => 'الحي الغربي', 'city_id' => $city->id, 'type' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, District::query()->count());
        $this->assertSame(1, Currency::query()->count());
    }

    public function test_a_location_in_use_is_not_deleted(): void
    {
        $city = $this->city();
        District::create(['name' => 'حي', 'city_id' => $city->id, 'type' => 1]);

        $this->delete(route('cities.destroy', $city->id))->assertSessionHas('error');
        $this->assertNotNull($city->fresh());

        // الحذف الجماعي يُبقي المدينة المستخدمة ويحذف غيرها.
        $free = City::create(['name' => 'يطا', 'governorate_id' => $city->governorate_id, 'latitude' => 31.4, 'longitude' => 35.1, 'population' => 1000, 'type' => 1]);

        $this->delete(route('cities.destroy-many'), ['ids' => [$free->id, $city->id]])->assertSessionHas('warning');
        $this->assertNull($free->fresh());
        $this->assertNotNull($city->fresh());
    }

    public function test_a_branch_is_added_with_its_working_hours(): void
    {
        $city = $this->city();

        $this->post(route('branches.store'), [
            'name' => 'فرع الخليل',
            'phone' => '0599000000',
            'country_id' => $this->country()->id,
            'city_id' => $city->id,
            'status' => 1,
            'opening_time' => '08:00',
            'closing_time' => '17:00',
            'working_hours' => [
                ['day' => 1, 'is_closed' => false, 'start_time' => '08:00', 'end_time' => '17:00'],
                ['day' => 6, 'is_closed' => true, 'start_time' => null, 'end_time' => null],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('branches.index'));

        $branch = Branch::query()->firstOrFail();
        $this->assertSame('فرع الخليل', $branch->translate('ar')->name);
        $this->assertSame(2, $branch->workingHours()->count());
    }

    public function test_a_branch_city_must_be_in_its_country(): void
    {
        $city = $this->city();
        $other = Country::query()->create(['name' => 'الأردن', 'code' => 'JO', 'phone_code' => '962', 'currency_id' => $this->currency()->id]);

        $this->post(route('branches.store'), [
            'name' => 'فرع', 'country_id' => $other->id, 'city_id' => $city->id, 'status' => 1,
        ])->assertSessionHasErrors('city_id');
    }

    private function currency(): Currency
    {
        return Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'دولار',
            'symbol' => '$',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function country(): Country
    {
        return Country::query()->firstOrCreate(['code' => 'PS'], [
            'name' => 'فلسطين',
            'phone_code' => '970',
            'currency_id' => $this->currency()->id,
        ]);
    }

    private function city(): City
    {
        $governorate = Governorate::query()->firstOrCreate(['code' => 'HBN'], ['name' => 'الخليل', 'country_id' => $this->country()->id]);

        return City::query()->firstOrCreate(['governorate_id' => $governorate->id], [
            'name' => 'دورا', 'latitude' => 31.5, 'longitude' => 35.1, 'population' => 1000, 'type' => 1,
        ]);
    }
}
