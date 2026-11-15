<?php

namespace Modules\Items\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\GeoLocation\Entities\City;
use Modules\GeoLocation\Entities\Country;
use Modules\GeoLocation\Entities\District;
use Modules\GeoLocation\Entities\Governorate;
use Modules\Items\Entities\Order;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\OrderStatus;
use Modules\Items\Enums\PaymentMethod;
use Modules\Items\Enums\PaymentStatus;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * طلبات المتجر في لوحة Vue: القائمة وتصفيتها، الاضافة والتعديل (السعر والمجاميع من الخادم)،
 * والحذف، كلها بصلاحياتها.
 */
class OrderPagesTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['Order View List', 'Order Create', 'Order Update', 'Order Delete', 'Order View', 'Order Info'];

    private User $user;

    private Branch $branch;

    private City $city;

    private District $district;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');
        $this->withoutMiddleware([\Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class, \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class]);
        $this->user = $this->signIn(self::ALL);
        $this->places();
    }

    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $this->actingAs($user);

        return $user;
    }

    private function places(): void
    {
        $currency = Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'دولار', 'symbol' => '$', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => 1, 'is_active' => true,
        ]);
        $country = Country::query()->firstOrCreate(['code' => 'PS'], ['name' => 'فلسطين', 'phone_code' => '970', 'currency_id' => $currency->id]);
        $governorate = Governorate::query()->firstOrCreate(['code' => 'HBN'], ['name' => 'الخليل', 'country_id' => $country->id]);
        $this->city = City::query()->create(['governorate_id' => $governorate->id, 'name' => 'دورا', 'type' => 1]);
        $this->district = District::query()->create(['city_id' => $this->city->id, 'name' => 'الحي الشرقي', 'type' => 1]);
        $this->branch = Branch::query()->create(['name' => 'فرع الخليل', 'country_id' => $country->id, 'city_id' => $this->city->id, 'created_by' => $this->user->id]);
    }

    private function product(string $name, float $price): Product
    {
        $product = new Product(['slug' => 'p-'.Str::random(8), 'sku' => 'O-'.Str::random(4), 'base_price' => $price, 'pricing_type' => 'simple']);
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    private function payload(array $items, array $overrides = []): array
    {
        return [
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
            'city_id' => $this->city->id,
            'district_id' => $this->district->id,
            'items' => $items,
            'delivery_fee' => 5,
            'status' => OrderStatus::PENDING->value,
            'payment_method' => PaymentMethod::CASH->value,
            'payment_status' => PaymentStatus::UNPAID->value,
            'delivery_address' => 'الخليل - شارع السلام',
            'contact_phone' => '0599000000',
            'notes' => 'ملاحظة',
            ...$overrides,
        ];
    }

    public function test_an_order_is_added_with_server_prices_and_totals(): void
    {
        $door = $this->product('باب', 10);
        $handle = $this->product('مقبض', 2.5);

        $this->get(route('orders.add'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Items/Order/Add')
            ->where('branches.0.label', 'فرع الخليل')
            ->has('statuses', count(OrderStatus::cases())));

        // السعر من الصنف ولو وصل غيره من الواجهة.
        $this->post(route('orders.store'), $this->payload([
            ['product_id' => $door->id, 'quantity' => 2, 'price' => 1],
            ['product_id' => $handle->id, 'quantity' => 4],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('orders.index'));

        $order = Order::query()->with('items')->firstOrFail();
        $this->assertEquals(30, $order->sub_total);
        $this->assertEquals(35, $order->total);
        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertSame((int) $this->user->id, (int) $order->created_by);
        $this->assertNotEmpty($order->order_number);
        $this->assertEquals([10, 2.5], $order->items->pluck('price')->map(fn ($price) => (float) $price)->all());
        $this->assertEquals([20, 10], $order->items->pluck('total_price')->map(fn ($price) => (float) $price)->all());
    }

    public function test_the_form_is_validated(): void
    {
        $door = $this->product('باب', 10);

        $this->post(route('orders.store'), $this->payload([]))->assertSessionHasErrors('items');
        $this->post(route('orders.store'), $this->payload([['product_id' => $door->id, 'quantity' => 0]], ['contact_phone' => '', 'status' => 99]))
            ->assertSessionHasErrors(['items.0.quantity', 'contact_phone', 'status']);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_order_is_edited_keeping_the_saved_line_price(): void
    {
        $door = $this->product('باب', 10);
        $handle = $this->product('مقبض', 2);
        $this->post(route('orders.store'), $this->payload([['product_id' => $door->id, 'quantity' => 1], ['product_id' => $handle->id, 'quantity' => 1]]));
        $order = Order::query()->with('items')->firstOrFail();
        [$doorLine, $handleLine] = $order->items->all();

        // سعر الصنف تغيّر بعد الطلب: السطر المحفوظ يبقى بسعره.
        $door->update(['base_price' => 50]);
        $glass = $this->product('زجاج', 7);

        $this->get(route('orders.edit', $order->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Items/Order/Edit')
            ->where('order.items.0.product_id', $door->id)
            ->where('labels.customers.0.label', $this->user->name)
            ->where('labels.cities.0.label', 'دورا'));

        $this->put(route('orders.update', $order->id), $this->payload([
            ['id' => $doorLine->id, 'product_id' => $door->id, 'quantity' => 3],
            ['product_id' => $glass->id, 'quantity' => 1],
        ], ['delivery_fee' => 0, 'status' => OrderStatus::CONFIRMED->value]))->assertSessionHasNoErrors()->assertRedirect(route('orders.index'));

        $order->refresh()->load('items');
        $this->assertNull($order->items->firstWhere('id', $handleLine->id));
        $this->assertEquals(10, $order->items->firstWhere('id', $doorLine->id)->price);
        $this->assertEquals(30 + 7, $order->sub_total);
        $this->assertEquals(37, $order->total);
        $this->assertSame(OrderStatus::CONFIRMED, $order->status);
    }

    public function test_the_list_filters_and_shows_status_badges(): void
    {
        $door = $this->product('باب', 10);
        $this->post(route('orders.store'), $this->payload([['product_id' => $door->id, 'quantity' => 1]]));
        $this->post(route('orders.store'), $this->payload([['product_id' => $door->id, 'quantity' => 1]], ['payment_status' => PaymentStatus::PAID->value]));

        $this->get(route('orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Items/Order/Index')
            ->has('table.data', 2)
            ->has('table.data.0.status.label')
            ->where('table.data.0.user.name', $this->user->name));

        $this->get(route('orders.index', ['filter' => ['payment_status' => PaymentStatus::PAID->value]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1));
        $this->get(route('orders.index', ['filter' => ['name' => 'لا احد']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 0));
    }

    public function test_lookups_answer_the_form_fields(): void
    {
        $door = $this->product('باب خشب', 12);

        $this->getJson(route('orders.lookup', ['type' => 'cities', 'q' => 'دور']))->assertOk()->assertJsonPath('0.label', 'دورا');
        $this->getJson(route('orders.lookup', ['type' => 'districts']))->assertJsonPath('0.value', $this->district->id);
        $this->getJson(route('orders.lookup', ['type' => 'customers', 'q' => $this->user->name]))->assertJsonPath('0.value', $this->user->id);
        $this->getJson(route('orders.edit-lookup', ['type' => 'products', 'q' => 'باب']))->assertJsonPath('0.value', $door->id)->assertJsonPath('0.price', 12);
    }

    public function test_delete_is_permanent_and_bulk_delete_is_soft(): void
    {
        $door = $this->product('باب', 10);
        $this->post(route('orders.store'), $this->payload([['product_id' => $door->id, 'quantity' => 1]]));
        $this->post(route('orders.store'), $this->payload([['product_id' => $door->id, 'quantity' => 1]]));
        [$first, $second] = Order::query()->orderBy('id')->get()->all();

        $this->from(route('orders.index'))->delete(route('orders.destroy', $first->id))->assertSessionHas('success');
        $this->assertNull(Order::withTrashed()->find($first->id));

        $this->delete(route('orders.destroy-many'), ['ids' => [$second->id]])->assertSessionHas('success');
        $this->assertNotNull(Order::withTrashed()->find($second->id)?->deleted_at);
    }

    public function test_every_route_needs_its_permission(): void
    {
        $door = $this->product('باب', 10);
        $this->post(route('orders.store'), $this->payload([['product_id' => $door->id, 'quantity' => 1]]));
        $order = Order::query()->firstOrFail();

        $this->signIn(['Order View List']);

        $this->get(route('orders.index'))->assertOk();
        $this->get(route('orders.add'))->assertForbidden();
        $this->post(route('orders.store'), $this->payload([['product_id' => $door->id, 'quantity' => 1]]))->assertForbidden();
        $this->get(route('orders.edit', $order->id))->assertForbidden();
        $this->put(route('orders.update', $order->id), $this->payload([['product_id' => $door->id, 'quantity' => 9]]))->assertForbidden();
        $this->delete(route('orders.destroy', $order->id))->assertForbidden();
        $this->getJson(route('orders.lookup', ['type' => 'customers']))->assertForbidden();
        $this->getJson(route('orders.info', $order->id))->assertForbidden();

        $this->assertSame(1, Order::query()->count());
    }
}
