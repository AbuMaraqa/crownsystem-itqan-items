<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\CustomerGroup;
use Modules\Accounting\Support\CustomerProfitMargin;
use Modules\Core\Entities\User;
use Modules\Items\Support\ProductSearch;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * نسبة ربح العميل: نسبته الخاصة تتقدم على نسبة تصنيفه، وتُضاف على سعر التكلفة
 * عند تسعير بنود البيع. صنف الاختبار: تكلفة 8 وسعر بيع 12.
 */
class CustomerProfitMarginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_customer_margin_overrides_the_group_margin(): void
    {
        $group = CustomerGroup::create(['name' => 'جملة', 'profit_margin' => 10]);
        $grouped = User::factory()->create(['customer_group_id' => $group->id]);
        $own = User::factory()->create(['customer_group_id' => $group->id, 'profit_margin' => 25]);
        $zero = User::factory()->create(['customer_group_id' => $group->id, 'profit_margin' => 0]);

        $this->assertSame(10.0, CustomerProfitMargin::for($grouped->id));
        $this->assertSame(25.0, CustomerProfitMargin::for($own->id));
        // صفر نسبة مقصودة (بيع بالتكلفة) لا فراغ.
        $this->assertSame(0.0, CustomerProfitMargin::for($zero->id));
    }

    public function test_no_margin_without_a_group_or_with_an_inactive_one(): void
    {
        $inactive = CustomerGroup::create(['name' => 'موقوف', 'profit_margin' => 30, 'is_active' => false]);

        $this->assertNull(CustomerProfitMargin::for(User::factory()->create()->id));
        $this->assertNull(CustomerProfitMargin::for(User::factory()->create(['customer_group_id' => $inactive->id])->id));
        $this->assertNull(CustomerProfitMargin::for(null));
    }

    public function test_the_margin_is_added_to_the_average_cost(): void
    {
        $product = ConsolidatedTestFactory::product();
        ConsolidatedTestFactory::averageCost($product, 8);

        // النسبة على متوسط التكلفة المرجّح من المشتريات، وبلا نسبة يبقى السعر الاساسي.
        $this->assertSame('10.0000', ProductSearch::salesItemPayload($product->id, 25)['unit_price']);
        $this->assertSame('8.0000', ProductSearch::salesItemPayload($product->id, 0)['unit_price']);
        $this->assertSame('10.0000', ProductSearch::salesItemPayload($product->id)['unit_price']);

        // صنف لم يُشترَ بعد: لا اساس للنسبة، فيبقى السعر الاساسي.
        ConsolidatedTestFactory::averageCost($product, 0);
        $this->assertSame('10.0000', ProductSearch::salesItemPayload($product->id, 25)['unit_price']);
    }

    public function test_a_sales_order_prices_items_with_the_client_margin(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('Sales Order Create', 'web'));
        $this->actingAs($user);

        $group = CustomerGroup::create(['name' => 'مقاولون', 'profit_margin' => 50]);
        $client = User::factory()->create(['customer_group_id' => $group->id]);
        $product = ConsolidatedTestFactory::product();
        ConsolidatedTestFactory::averageCost($product, 8);
        // سعر السطر في جدول اصناف طلبية البيع (Vue) للطرف المختار: متوسط التكلفة 8 + نسبة الزبون.
        $price = fn (?int $party) => $this->getJson(route('accounting.items-grid.item', array_filter([
            'document' => 'sales-order',
            'product_id' => $product->id,
            'party_id' => $party,
        ])))->assertOk()->json('unit_price');

        // بلا زبون لا سعر تلقائي، كما في النظام القديم.
        $this->assertSame('0.0000', $price(null));
        $this->assertSame('12.0000', $price($client->id));

        $client->update(['profit_margin' => 20]);
        $this->assertSame('9.6000', $price($client->id));
    }

    public function test_the_group_screen_moves_customers_into_and_out_of_the_group(): void
    {
        $user = User::factory()->create();
        $permissions = ['Customer Group View List', 'Customer Group Create', 'Customer Group Update', 'Customer Group Delete'];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        Role::findOrCreate('Customer', 'web');
        [$first, $second] = [User::factory()->create()->assignRole('Customer'), User::factory()->create()->assignRole('Customer')];

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $this->post(route('accounting.customer-groups.store'), ['name' => 'تجزئة', 'profit_margin' => 15, 'customer_ids' => [$first->id, $second->id], 'is_active' => true])
            ->assertSessionHasNoErrors();

        $group = CustomerGroup::query()->where('name', 'تجزئة')->firstOrFail();
        $this->assertSame([$first->id, $second->id], $group->customers()->orderBy('id')->pluck('id')->all());

        $this->put(route('accounting.customer-groups.update', $group->id), ['name' => 'تجزئة', 'profit_margin' => 15, 'customer_ids' => [$second->id], 'is_active' => true])
            ->assertSessionHasNoErrors();

        $this->assertNull($first->fresh()->customer_group_id);
        $this->assertSame($group->id, $second->fresh()->customer_group_id);

        $this->delete(route('accounting.customer-groups.destroy', $group->id))->assertSessionHasNoErrors();

        $this->assertNull($second->fresh()->customer_group_id);
    }
}
