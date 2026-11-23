<?php

namespace Modules\Core\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\CurrencyExchangeRate;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Core\Support\SafeDelete;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * صفحات العملات في لوحة Vue: القائمة والاضافة والتعديل والحذف، كلها بصلاحياتها.
 */
class CurrencyPagesTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['Currency View List', 'Currency Create', 'Currency Update', 'Currency Delete'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        foreach (self::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function signIn(array $permissions = self::ALL): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function currency(string $code = 'USD'): Currency
    {
        return Currency::query()->firstOrCreate(['code' => $code], [
            'name' => 'دولار',
            'symbol' => '$',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'يورو',
            'plural_name' => 'يوروهات',
            'code' => 'EUR',
            'symbol' => '€',
            'symbol_position' => 'after',
            'decimal_places' => 2,
            'exchange_rate' => 0.9,
            'is_active' => true,
            ...$overrides,
        ];
    }

    public function test_the_list_is_an_inertia_table(): void
    {
        $this->signIn();
        $this->currency();

        $this->get(route('currencies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Core/Currency/Index')
                ->has('table.data', 1)
                ->where('table.data.0.code', 'USD')
                ->where('table.meta.total', 1));
    }

    public function test_the_search_filter_narrows_the_list(): void
    {
        $this->signIn();
        $this->currency('USD');
        $this->currency('JOD');

        $this->get(route('currencies.index', ['filter' => ['search' => 'JOD']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.code', 'JOD'));
    }

    public function test_a_currency_is_added_with_its_arabic_name(): void
    {
        $this->signIn();

        $this->post(route('currencies.store'), $this->payload())->assertRedirect(route('currencies.index'));

        $currency = Currency::query()->where('code', 'EUR')->firstOrFail();
        $this->assertSame('يورو', $currency->translate('ar')->name);
        $this->assertSame('€', $currency->symbol);
    }

    public function test_edit_updates_the_currency_instead_of_creating_one(): void
    {
        $this->signIn();
        $currency = $this->currency();

        $this->get(route('currencies.edit', $currency->id))
            ->assertInertia(fn (Assert $page) => $page->component('Core/Currency/Edit')->where('currency.code', 'USD'));

        $this->put(route('currencies.update', $currency->id), $this->payload(['code' => 'USD', 'symbol' => 'US$']))
            ->assertRedirect(route('currencies.index'));

        $this->assertSame(1, Currency::query()->count());
        $this->assertSame('US$', $currency->refresh()->symbol);
    }

    public function test_a_code_must_be_unique(): void
    {
        $this->signIn();
        $this->currency('EUR');

        $this->post(route('currencies.store'), $this->payload())->assertSessionHasErrors('code');
    }

    public function test_deleting_a_linked_currency_is_refused_with_a_message(): void
    {
        $user = $this->signIn();
        $base = $this->currency('USD');
        $linked = $this->currency('JOD');
        CurrencyExchangeRate::query()->create([
            'base_currency_id' => $base->id,
            'currency_id' => $linked->id,
            'rate_date' => now()->toDateString(),
            'exchange_rate' => 0.71,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $this->delete(route('currencies.destroy', $linked->id))->assertSessionHas('error', SafeDelete::message());
        $this->assertNotNull($linked->fresh());

        // الحذف الجماعي يحذف غير المرتبطة ويُبقي المرتبطة مع ذكر السبب.
        $free = $this->currency('EGP');
        $this->delete(route('currencies.destroy-many'), ['ids' => [$free->id, $linked->id]])->assertSessionHas('warning');
        $this->assertNull($free->fresh());
        $this->assertNotNull($linked->fresh());

        $this->delete(route('currencies.destroy-many'), ['ids' => [$linked->id]])->assertSessionHas('error');
        $this->assertNotNull($linked->fresh());
    }

    public function test_an_unlinked_currency_is_deleted(): void
    {
        $this->signIn();
        $currency = $this->currency();

        $this->delete(route('currencies.destroy', $currency->id))->assertSessionHas('success');
        $this->assertNull($currency->fresh());
    }

    public function test_every_action_needs_its_permission(): void
    {
        $this->signIn(['Currency View List']);
        $currency = $this->currency();

        $this->get(route('currencies.index'))->assertOk();
        $this->get(route('currencies.add'))->assertForbidden();
        $this->post(route('currencies.store'), $this->payload())->assertForbidden();
        $this->put(route('currencies.update', $currency->id), $this->payload())->assertForbidden();
        $this->delete(route('currencies.destroy', $currency->id))->assertForbidden();
    }
}
