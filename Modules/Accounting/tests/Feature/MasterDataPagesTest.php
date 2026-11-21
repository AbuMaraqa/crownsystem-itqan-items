<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountMapping;
use Modules\Accounting\Entities\AdditionalCostType;
use Modules\Accounting\Entities\AttachmentCategory;
use Modules\Accounting\Entities\ClearanceAttachmentType;
use Modules\Accounting\Entities\CurrencyExchangeRate;
use Modules\Accounting\Entities\CustomerGroup;
use Modules\Accounting\Entities\DeliveryCostElement;
use Modules\Accounting\Entities\ReappraisalElement;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * صفحات البيانات الاساسية للمحاسبة في لوحة Vue (بنود التكاليف، تصنيفات المرفقات، انواع مرفقات
 * التخليص، عناصر تكلفة التوصيل، بنود اعادة التخمين، الضرائب، تصنيفات العملاء، اسعار الصرف،
 * ربط الحسابات): القائمة والاضافة والتعديل والتفعيل والحذف، كلها بصلاحياتها.
 */
class MasterDataPagesTest extends TestCase
{
    use RefreshDatabase;

    private const ENTITIES = [
        'Additional Cost Type', 'Attachment Category', 'Clearance Attachment Type', 'Delivery Cost Element',
        'Reappraisal Element', 'Tax', 'Customer Group', 'Currency Exchange Rate',
    ];

    private const ACTIONS = ['View List', 'Create', 'Update', 'Delete'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        foreach ($this->permissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    /**
     * @return list<string>
     */
    private function permissions(): array
    {
        $names = ['Tax View', 'Tax Info', 'Currency Exchange Rate View', 'Currency Exchange Rate Info', 'Accounting Mapping View List', 'Accounting Mapping Update'];

        foreach (self::ENTITIES as $entity) {
            foreach (self::ACTIONS as $action) {
                $names[] = "{$entity} {$action}";
            }
        }

        return $names;
    }

    private function signIn(?array $permissions = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions ?? $this->permissions());
        $this->actingAs($user);

        return $user;
    }

    /**
     * The simple lookups: name (and Active) only.
     *
     * @return array<string, array{0: class-string<Model>, 1: string, 2: string, 3: string, 4: bool}>
     */
    public static function lookups(): array
    {
        return [
            'additional cost types' => [AdditionalCostType::class, 'additional-cost-types', 'AdditionalCostType', 'Additional Cost Type', true],
            'attachment categories' => [AttachmentCategory::class, 'attachment-categories', 'AttachmentCategory', 'Attachment Category', false],
            'clearance attachment types' => [ClearanceAttachmentType::class, 'clearance-attachment-types', 'ClearanceAttachmentType', 'Clearance Attachment Type', false],
            'delivery cost elements' => [DeliveryCostElement::class, 'delivery-cost-elements', 'DeliveryCostElement', 'Delivery Cost Element', false],
            'reappraisal elements' => [ReappraisalElement::class, 'reappraisal-elements', 'ReappraisalElement', 'Reappraisal Element', true],
        ];
    }

    /**
     * @param  class-string<Model>  $class
     */
    #[DataProvider('lookups')]
    public function test_a_lookup_is_listed_added_edited_toggled_and_deleted(string $class, string $routes, string $page, string $entity, bool $hasActive): void
    {
        $user = $this->signIn();
        $class::create(['name' => 'بند قديم', 'created_by' => $user->id]);

        $this->get(route("accounting.{$routes}.index"))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component("Accounting/{$page}/Index")
                ->has('table.data', 1)
                ->where('table.data.0.name', 'بند قديم'));

        $this->get(route("accounting.{$routes}.index", ['filter' => ['search' => 'غير موجود']]))
            ->assertInertia(fn (Assert $inertia) => $inertia->has('table.data', 0));

        $payload = $hasActive ? ['name' => 'بند جديد', 'is_active' => false] : ['name' => 'بند جديد'];
        $this->post(route("accounting.{$routes}.store"), $payload)->assertRedirect()->assertSessionHas('success');

        $record = $class::query()->where('name', 'بند جديد')->firstOrFail();
        $this->assertSame($user->id, (int) $record->created_by);

        if ($hasActive) {
            $this->assertFalse($record->is_active);
        }

        $this->post(route("accounting.{$routes}.store"), ['name' => ''])->assertSessionHasErrors('name');

        $this->put(route("accounting.{$routes}.update", $record->id), ['name' => 'بند معدل'])->assertSessionHas('success');
        $this->assertSame('بند معدل', $record->fresh()->name);

        if ($hasActive) {
            $this->put(route("accounting.{$routes}.active", $record->id), ['is_active' => true])->assertSessionHas('success');
            $this->assertTrue($record->fresh()->is_active);
        }

        $this->delete(route("accounting.{$routes}.destroy", $record->id))->assertSessionHas('success');
        $this->assertSoftDeleted($record);
    }

    /**
     * @param  class-string<Model>  $class
     */
    #[DataProvider('lookups')]
    public function test_a_lookup_follows_its_permissions(string $class, string $routes, string $page, string $entity, bool $hasActive): void
    {
        $record = $class::create(['name' => 'بند']);

        $this->signIn([]);
        $this->get(route("accounting.{$routes}.index"))->assertForbidden();

        $this->signIn(["{$entity} View List"]);
        $this->get(route("accounting.{$routes}.index"))->assertOk();
        $this->post(route("accounting.{$routes}.store"), ['name' => 'جديد'])->assertForbidden();
        $this->put(route("accounting.{$routes}.update", $record->id), ['name' => 'معدل'])->assertForbidden();
        $this->delete(route("accounting.{$routes}.destroy", $record->id))->assertForbidden();

        if ($hasActive) {
            $this->put(route("accounting.{$routes}.active", $record->id), ['is_active' => false])->assertForbidden();
        }

        $this->assertSame('بند', $record->fresh()->name);
        $this->assertNotSoftDeleted($record);
    }

    public function test_taxes_keep_their_code_rate_and_status(): void
    {
        $this->signIn();

        $this->post(route('accounting.taxes.store'), ['name' => 'ضريبة القيمة المضافة', 'code' => 'VAT', 'rate' => 16, 'is_active' => true])
            ->assertSessionHas('success');
        $tax = Tax::query()->where('code', 'VAT')->firstOrFail();

        $this->get(route('accounting.taxes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Tax/Index')
                ->where('table.data.0.rate_label', '16.00%')
                ->where('table.data.0.is_active', true));

        $this->post(route('accounting.taxes.store'), ['name' => 'خطأ', 'rate' => 120])->assertSessionHasErrors('rate');

        $this->put(route('accounting.taxes.update', $tax->id), ['name' => 'ضريبة', 'code' => 'VAT', 'rate' => 8, 'is_active' => true]);
        $this->assertSame('8.0000', (string) $tax->fresh()->rate);

        $this->put(route('accounting.taxes.active', $tax->id), ['is_active' => false])->assertSessionHas('success');
        $this->assertFalse($tax->fresh()->is_active);

        $this->getJson(route('accounting.taxes.info', $tax->id))->assertOk()->assertJsonStructure(['created_by', 'created_at', 'activities']);

        $this->delete(route('accounting.taxes.destroy', $tax->id))->assertSessionHas('success');
        $this->assertSoftDeleted($tax);
    }

    public function test_tax_info_follows_the_list_permission(): void
    {
        $tax = Tax::create(['name' => 'ضريبة', 'rate' => 16]);

        $this->signIn([]);
        $this->getJson(route('accounting.taxes.info', $tax->id))->assertForbidden();

        // Info is basic: whoever sees the list sees the row's history (View List).
        $this->signIn(['Tax View List']);
        $this->getJson(route('accounting.taxes.info', $tax->id))->assertOk();
    }

    public function test_a_customer_group_moves_customers_into_and_out_of_it(): void
    {
        $this->signIn();

        Role::findOrCreate('Customer', 'web');
        $first = User::factory()->create(['name' => 'زبون أول'])->assignRole('Customer');
        $second = User::factory()->create(['name' => 'زبون ثان'])->assignRole('Customer');
        $notCustomer = User::factory()->create(['name' => 'زبون مزيف']);

        $this->getJson(route('accounting.customer-groups.customers', ['q' => 'زبون']))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonMissing(['value' => $notCustomer->id]);

        $this->post(route('accounting.customer-groups.store'), [
            'name' => 'تجزئة',
            'profit_margin' => 15,
            'use_last_price' => true,
            'customer_ids' => [$first->id, $second->id],
            'notes' => 'ملاحظة',
            'is_active' => true,
        ])->assertSessionHas('success');

        $group = CustomerGroup::query()->where('name', 'تجزئة')->firstOrFail();
        $this->assertSame([$first->id, $second->id], $group->customers()->orderBy('id')->pluck('id')->all());
        $this->assertTrue($group->use_last_price);

        $this->get(route('accounting.customer-groups.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/CustomerGroup/Index')
                ->where('table.data.0.profit_margin', '15')
                ->where('table.data.0.customers_count', 2)
                ->has('table.data.0.customers', 2)
                ->has('priceLists'));

        $this->put(route('accounting.customer-groups.update', $group->id), [
            'name' => 'تجزئة',
            'profit_margin' => 15,
            'customer_ids' => [$second->id],
            'is_active' => true,
        ])->assertSessionHas('success');

        $this->assertNull($first->fresh()->customer_group_id);
        $this->assertSame($group->id, (int) $second->fresh()->customer_group_id);

        $this->post(route('accounting.customer-groups.store'), ['name' => 'خطأ', 'profit_margin' => 2000])->assertSessionHasErrors('profit_margin');

        $this->put(route('accounting.customer-groups.active', $group->id), ['is_active' => false])->assertSessionHas('success');
        $this->assertFalse($group->fresh()->is_active);

        $this->delete(route('accounting.customer-groups.destroy', $group->id))->assertSessionHas('success');

        $this->assertSoftDeleted($group);
        $this->assertNull($second->fresh()->customer_group_id);
    }

    public function test_customer_group_writes_follow_their_permissions(): void
    {
        $group = CustomerGroup::create(['name' => 'جملة', 'profit_margin' => 10]);

        $this->signIn(['Customer Group View List']);

        $this->get(route('accounting.customer-groups.index'))->assertOk();
        $this->post(route('accounting.customer-groups.store'), ['name' => 'جديد', 'profit_margin' => 1])->assertForbidden();
        $this->put(route('accounting.customer-groups.update', $group->id), ['name' => 'معدل', 'profit_margin' => 1])->assertForbidden();
        $this->put(route('accounting.customer-groups.active', $group->id), ['is_active' => false])->assertForbidden();
        $this->delete(route('accounting.customer-groups.destroy', $group->id))->assertForbidden();
    }

    /**
     * @return array{0: int, 1: Currency}
     */
    private function currencies(): array
    {
        foreach (['USD' => 'دولار', 'EUR' => 'يورو', 'JOD' => 'دينار'] as $code => $name) {
            Currency::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'symbol' => $code,
                'symbol_position' => 'before',
                'decimal_places' => 2,
                'exchange_rate' => 1,
                'is_active' => true,
            ]);
        }

        $baseId = (int) app(CurrencyExchangeRateService::class)->baseCurrencyId();
        $other = Currency::query()->whereKeyNot($baseId)->orderBy('id')->firstOrFail();

        return [$baseId, $other];
    }

    public function test_an_exchange_rate_is_one_per_currency_and_date(): void
    {
        $this->signIn();
        [$baseId, $currency] = $this->currencies();

        $this->post(route('accounting.currency-exchange-rates.store'), [
            'currency_id' => $currency->id,
            'rate_date' => '2026-11-01',
            'exchange_rate' => 0.71,
            'is_active' => true,
        ])->assertSessionHas('success');

        // The same currency and date again updates the saved rate, also after it was deleted.
        CurrencyExchangeRate::query()->firstOrFail()->delete();

        $this->post(route('accounting.currency-exchange-rates.store'), [
            'currency_id' => $currency->id,
            'rate_date' => '2026-11-01',
            'exchange_rate' => 0.72,
            'base_currency_id' => 999999,
        ])->assertSessionHas('success');

        $this->assertSame(1, CurrencyExchangeRate::withTrashed()->count());
        $rate = CurrencyExchangeRate::query()->firstOrFail();
        $this->assertSame('0.72000000', (string) $rate->exchange_rate);
        $this->assertSame($baseId, (int) $rate->base_currency_id);

        $this->get(route('accounting.currency-exchange-rates.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/CurrencyExchangeRate/Index')
                ->has('table.data', 1)
                ->where('table.data.0.currency', $currency->code)
                ->where('table.data.0.rate_date', '2026-11-01')
                ->where('table.data.0.exchange_rate_label', '0.72000000')
                ->has('currencies'));

        $this->get(route('accounting.currency-exchange-rates.index', ['sort' => '-currency']))->assertOk();

        $this->post(route('accounting.currency-exchange-rates.store'), ['currency_id' => $currency->id, 'rate_date' => '2026-11-01', 'exchange_rate' => 0])
            ->assertSessionHasErrors('exchange_rate');

        $this->getJson(route('accounting.currency-exchange-rates.info', $rate->id))->assertOk()->assertJsonStructure(['activities']);
    }

    public function test_editing_a_rate_onto_another_rates_date_is_refused(): void
    {
        $this->signIn();
        [$baseId, $currency] = $this->currencies();

        $first = CurrencyExchangeRate::create(['base_currency_id' => $baseId, 'currency_id' => $currency->id, 'rate_date' => '2026-11-01', 'exchange_rate' => 0.7, 'is_active' => true]);
        $second = CurrencyExchangeRate::create(['base_currency_id' => $baseId, 'currency_id' => $currency->id, 'rate_date' => '2026-11-02', 'exchange_rate' => 0.8, 'is_active' => true]);

        $this->put(route('accounting.currency-exchange-rates.update', $second->id), [
            'currency_id' => $currency->id,
            'rate_date' => '2026-11-01',
            'exchange_rate' => 0.9,
            'is_active' => true,
        ])->assertSessionHas('error', __('Exchange rate already exists for selected currency and date.'));

        $this->assertSame('2026-11-02', $second->fresh()->rate_date->toDateString());

        $this->put(route('accounting.currency-exchange-rates.update', $second->id), [
            'currency_id' => $currency->id,
            'rate_date' => '2026-11-03',
            'exchange_rate' => 0.9,
            'is_active' => false,
        ])->assertSessionHas('success');

        $second->refresh();
        $this->assertSame('2026-11-03', $second->rate_date->toDateString());
        $this->assertFalse($second->is_active);

        $this->delete(route('accounting.currency-exchange-rates.destroy', $first->id))->assertSessionHas('success');
        $this->assertSoftDeleted($first);
    }

    public function test_exchange_rate_writes_follow_their_permissions(): void
    {
        [$baseId, $currency] = $this->currencies();
        $rate = CurrencyExchangeRate::create(['base_currency_id' => $baseId, 'currency_id' => $currency->id, 'rate_date' => '2026-11-01', 'exchange_rate' => 0.7]);

        $this->signIn(['Currency Exchange Rate View List']);

        $this->get(route('accounting.currency-exchange-rates.index'))->assertOk();
        $this->post(route('accounting.currency-exchange-rates.store'), ['currency_id' => $currency->id, 'rate_date' => '2026-11-05', 'exchange_rate' => 1])->assertForbidden();
        $this->put(route('accounting.currency-exchange-rates.update', $rate->id), ['currency_id' => $currency->id, 'rate_date' => '2026-11-05', 'exchange_rate' => 1])->assertForbidden();
        $this->delete(route('accounting.currency-exchange-rates.destroy', $rate->id))->assertForbidden();
        // Info is basic: whoever sees the list sees the row's history (View List).
        $this->getJson(route('accounting.currency-exchange-rates.info', $rate->id))->assertOk();
    }

    public function test_an_account_mapping_points_to_a_postable_account(): void
    {
        $this->seed(DefaultChartOfAccountsSeeder::class);
        $this->seed(AccountMappingSeeder::class);
        $this->signIn();

        $mapping = AccountMapping::query()->orderBy('id')->firstOrFail();
        $postable = Account::query()->postable()->whereKeyNot((int) $mapping->account_id)->firstOrFail();
        $group = Account::query()->where('is_postable', false)->firstOrFail();

        $this->get(route('accounting.account-mappings.index', ['filter' => ['source_module' => $mapping->source_module]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/AccountMapping/Index')
                ->where('table.data.0.source_module', $mapping->source_module)
                ->has('accounts')
                ->has('modules'));

        $this->put(route('accounting.account-mappings.update', $mapping->id), ['account_id' => $group->id])->assertSessionHasErrors('account_id');

        $this->put(route('accounting.account-mappings.update', $mapping->id), ['account_id' => $postable->id])->assertSessionHas('success');
        $this->assertSame($postable->id, (int) $mapping->fresh()->account_id);

        $this->put(route('accounting.account-mappings.update', $mapping->id), ['account_id' => null])->assertSessionHas('success');
        $this->assertNull($mapping->fresh()->account_id);

        $this->get(route('accounting.account-mappings.index', ['filter' => ['mapped' => '0']]))
            ->assertInertia(fn (Assert $page) => $page->where('table.data.0.account', null));
    }

    public function test_account_mappings_follow_their_permissions(): void
    {
        $this->seed(DefaultChartOfAccountsSeeder::class);
        $this->seed(AccountMappingSeeder::class);
        $mapping = AccountMapping::query()->orderBy('id')->firstOrFail();

        $this->signIn([]);
        $this->get(route('accounting.account-mappings.index'))->assertForbidden();

        $this->signIn(['Accounting Mapping View List']);
        $this->get(route('accounting.account-mappings.index'))->assertOk();
        $this->put(route('accounting.account-mappings.update', $mapping->id), ['account_id' => null])->assertForbidden();
    }
}
