<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;
use Modules\Items\Entities\ProductPrice;
use Modules\Items\Entities\ProductUnit;
use Modules\Items\Entities\Unit;
use Modules\Items\Support\ItemModelPermissions;
use Modules\Items\Support\PriceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * صفحات انواع الاصناف وقوائم الاسعار ووحدات الصنف واسعاره: الصلاحيات والعمليات.
 */
class ItemModelPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
    }

    private function actingWith(array $permissions = []): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function unit(string $name = 'قطعة'): Unit
    {
        $unit = new Unit(['slug' => 'unit-'.Str::lower(Str::random(8)), 'created_by' => User::factory()->create()->id]);
        $unit->save();
        $unit->translateOrNew(app()->getLocale())->name = $name;
        $unit->save();

        return $unit;
    }

    private function product(array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'slug' => 'item-'.Str::lower(Str::random(8)),
            'base_price' => 0,
            'unit_id' => $this->unit()->id,
        ], $attributes));
        $product->save();
        $product->translateOrNew('ar')->name = 'صنف '.$product->id;
        $product->save();

        return $product;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatedRoutes(): array
    {
        return [
            'item types' => ['item-type-profiles.index', 'Item Type Profile View List'],
            'price lists' => ['price-lists.index', 'Price List View List'],
        ];
    }

    #[DataProvider('gatedRoutes')]
    public function test_a_page_needs_its_permission(string $route, string $permission): void
    {
        $this->actingWith();

        $this->get(route($route))->assertForbidden();
    }

    #[DataProvider('gatedRoutes')]
    public function test_a_page_opens_with_its_permission(string $route, string $permission): void
    {
        $this->actingWith([$permission]);

        $this->get(route($route))->assertOk();
    }

    public function test_the_units_and_prices_page_needs_its_permission(): void
    {
        $product = $this->product();

        $this->actingWith(['Product Update']);
        $this->get(route('products.units-and-prices', $product))->assertForbidden();

        $this->actingWith([ItemModelPermissions::UNITS_AND_PRICES]);
        $this->get(route('products.units-and-prices', $product))->assertOk();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('price-lists.index'))->assertRedirect();
    }

    public function test_permissions_are_synced_to_the_super_admin(): void
    {
        $role = Role::query()->where('name', 'Super Admin')->where('guard_name', 'web')->firstOrFail();

        foreach (ItemModelPermissions::GROUPS as $names) {
            foreach ($names as $name) {
                $this->assertTrue($role->hasPermissionTo($name), $name);
            }
        }
    }

    public function test_page_actions_follow_their_permissions(): void
    {
        $this->actingWith(['Price List View List']);
        $list = PriceList::create(['name' => 'تجزئة']);

        $this->post(route('price-lists.store'), ['name' => 'جملة', 'sort_order' => 0])->assertForbidden();
        $this->put(route('price-lists.update', $list->id), ['name' => 'جملة', 'sort_order' => 0])->assertForbidden();
        $this->delete(route('price-lists.destroy', $list->id))->assertForbidden();

        $this->actingWith(['Price List View List', 'Price List Create', 'Price List Update', 'Price List Delete']);

        $this->post(route('price-lists.store'), ['name' => 'جملة', 'sort_order' => 0])->assertSessionHasNoErrors()->assertRedirect();
        $this->put(route('price-lists.update', $list->id), ['name' => 'تجزئة', 'sort_order' => 0])->assertSessionHasNoErrors()->assertRedirect();
        $this->delete(route('price-lists.destroy', $list->id))->assertSessionHas('success');
    }

    public function test_the_price_list_page_creates_edits_and_deletes(): void
    {
        $user = $this->actingWith(['Price List View List', 'Price List Create', 'Price List Update', 'Price List Delete']);

        $this->post(route('price-lists.store'), ['name' => 'جملة', 'sort_order' => 2, 'same_buy_sell' => true, 'is_active' => true])
            ->assertSessionHasNoErrors();

        $list = PriceList::query()->whereTranslation('name', 'جملة')->firstOrFail();

        $this->assertTrue($list->same_buy_sell);
        $this->assertSame($user->id, $list->created_by);

        $this->put(route('price-lists.update', $list->id), ['name' => 'جملة كبرى', 'sort_order' => 2, 'same_buy_sell' => true, 'is_active' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame('جملة كبرى', $list->refresh()->name);

        $this->delete(route('price-lists.destroy', $list->id));

        $this->assertNull(PriceList::query()->find($list->id));
    }

    public function test_the_page_keeps_a_list_that_has_prices(): void
    {
        $this->actingWith(['Price List View List', 'Price List Delete']);
        $list = PriceList::create(['name' => 'تجزئة']);
        ProductPrice::create(['price_list_id' => $list->id, 'product_id' => $this->product()->id, 'sell_price' => 1]);

        $this->delete(route('price-lists.destroy', $list->id))->assertSessionHas('error');

        $this->assertNotNull(PriceList::query()->find($list->id));
    }

    /* ---------- صفحة الوحدات والاسعار ---------- */

    public function test_actions_are_refused_without_the_permission(): void
    {
        $product = $this->product();
        $unit = ProductUnit::create(['product_id' => $product->id, 'unit_id' => $this->unit()->id, 'factor' => 6]);

        $this->actingWith(['Product Update']);

        $this->put(route('products.units-and-prices.prices.update', $product->id), ['prices' => []])->assertForbidden();
        $this->post(route('products.units-and-prices.units.store', $product->id), ['unit_id' => $this->unit()->id, 'factor' => 2])->assertForbidden();
        $this->delete(route('products.units-and-prices.units.destroy', [$product->id, $unit->id]))->assertForbidden();
        $this->post(route('products.units-and-prices.barcodes.store', $product->id), ['barcode' => 'X'])->assertForbidden();

        $this->assertNotNull(ProductUnit::query()->find($unit->id));
    }

    public function test_units_barcodes_and_prices_are_managed_from_the_page(): void
    {
        $user = $this->actingWith([ItemModelPermissions::UNITS_AND_PRICES]);
        $product = $this->product();
        $carton = $this->unit('كرتونة');
        $list = PriceList::create(['name' => 'تجزئة']);
        $derived = PriceList::create(['name' => 'جملة']);

        $this->post(route('products.units-and-prices.units.store', $product->id), ['unit_id' => $carton->id, 'factor' => '12'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('Unit saved successfully'));

        $productUnit = ProductUnit::query()->where('product_id', $product->id)->firstOrFail();

        $this->assertSame('12.000000', $productUnit->factor);
        $this->assertSame($user->id, $productUnit->created_by);

        $this->post(route('products.units-and-prices.barcodes.store', $product->id), ['barcode' => '*777', 'product_unit_id' => $productUnit->id, 'pack_quantity' => '2', 'is_prefix' => false])
            ->assertSessionHasNoErrors();

        $barcode = ProductBarcode::query()->where('product_id', $product->id)->firstOrFail();

        $this->assertSame('777', $barcode->barcode);
        $this->assertTrue($barcode->is_prefix);
        $this->assertSame($productUnit->id, $barcode->product_unit_id);

        $this->put(route('products.units-and-prices.prices.update', $product->id), ['prices' => [
            $list->id => [
                0 => ['sell_price' => '10', 'buy_price' => '8', 'source_price_list_id' => null, 'source_factor' => ''],
                $productUnit->id => ['sell_price' => '115', 'buy_price' => '', 'source_price_list_id' => null, 'source_factor' => ''],
            ],
            $derived->id => [
                0 => ['sell_price' => '', 'buy_price' => '', 'source_price_list_id' => null, 'source_factor' => ''],
                $productUnit->id => ['sell_price' => '', 'buy_price' => '', 'source_price_list_id' => $list->id, 'source_factor' => '0.9'],
            ],
        ]])->assertSessionHasNoErrors();

        $this->assertSame('10.0000', PriceResolver::resolve($product, $list));
        $this->assertSame('8.0000', PriceResolver::resolve($product, $list, side: 'buy'));
        $this->assertSame('115.0000', PriceResolver::resolve($product, $list, $productUnit));
        $this->assertSame('103.5000', PriceResolver::resolve($product, $derived, $productUnit));
        // صف فارغ لا يُنشئ سعراً.
        $this->assertNull(PriceResolver::resolve($product, $derived));

        // الصفحة تعرض السعر المحسوب بجانب الصف الذي له قائمة مصدر.
        $this->get(route('products.units-and-prices', $product->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Product/UnitsAndPrices')
                ->has('units', 1)
                ->has('barcodes', 1)
                ->has('unitRows', 2)
                ->where("calculated.{$derived->id}.{$productUnit->id}", fn ($value) => str_starts_with((string) $value, '103.5000')));

        $this->delete(route('products.units-and-prices.barcodes.destroy', [$product->id, $barcode->id]))->assertSessionHas('success');
        $this->delete(route('products.units-and-prices.units.destroy', [$product->id, $productUnit->id]))->assertSessionHas('success');

        $this->assertSame(0, ProductBarcode::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, ProductUnit::query()->where('product_id', $product->id)->count());
        // سعر الوحدة الاساسية لا يتأثر بحذف وحدة اخرى.
        $this->assertSame('10.0000', PriceResolver::resolve($product, $list));
    }

    public function test_validation_errors_reach_the_page_and_nothing_is_partly_saved(): void
    {
        $this->actingWith([ItemModelPermissions::UNITS_AND_PRICES]);
        $product = $this->product();
        $good = PriceList::create(['name' => 'أ', 'sort_order' => 1]);
        $bad = PriceList::create(['name' => 'ب', 'sort_order' => 2]);

        $this->post(route('products.units-and-prices.units.store', $product->id), ['unit_id' => $this->unit('علبة')->id, 'factor' => '0'])
            ->assertSessionHasErrors('factor');

        $this->post(route('products.units-and-prices.barcodes.store', $product->id), ['barcode' => '', 'product_unit_id' => null, 'pack_quantity' => 1, 'is_prefix' => false])
            ->assertSessionHasErrors('barcode');

        $this->put(route('products.units-and-prices.prices.update', $product->id), ['prices' => [
            $good->id => [0 => ['sell_price' => '5']],
            $bad->id => [0 => ['sell_price' => '-5']],
        ]])->assertSessionHasErrors('sell_price');

        $this->assertSame(0, ProductPrice::query()->where('product_id', $product->id)->count());
    }

    public function test_a_row_of_another_item_cannot_be_deleted_from_the_page(): void
    {
        $this->actingWith([ItemModelPermissions::UNITS_AND_PRICES]);
        $other = $this->product();
        $otherUnit = ProductUnit::create(['product_id' => $other->id, 'unit_id' => $this->unit()->id, 'factor' => 6]);

        $this->delete(route('products.units-and-prices.units.destroy', [$this->product()->id, $otherUnit->id]))->assertNotFound();

        $this->assertNotNull(ProductUnit::query()->find($otherUnit->id));
    }

    public function test_a_barcode_used_by_another_item_saves_with_a_notice_only(): void
    {
        $this->actingWith([ItemModelPermissions::UNITS_AND_PRICES]);
        $this->product(['barcode' => 'SHARED-P']);
        $product = $this->product();

        $this->post(route('products.units-and-prices.barcodes.store', $product->id), ['barcode' => 'SHARED-P', 'product_unit_id' => null, 'pack_quantity' => 1, 'is_prefix' => false])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('barcode_notice', __('This barcode also exists on :count other items.', ['count' => 1]));

        $this->assertTrue(ProductBarcode::query()->where('product_id', $product->id)->where('barcode', 'SHARED-P')->exists());
    }

    public function test_the_tree_links_to_the_page_only_with_the_permission(): void
    {
        $product = $this->product(['pricing_type' => 'simple']);
        $url = route('products.units-and-prices', $product->id);

        // الزر في شريط الشجرة يظهر بـ can() من صلاحيات المستخدم المشتركة مع الصفحة، والصفحة نفسها بصلاحيتها.
        $treeAllows = fn (bool $allowed) => fn (Assert $page) => $page
            ->component('Items/ItemTree/Index')
            ->where('auth.permissions', fn ($permissions): bool => collect($permissions)->contains(ItemModelPermissions::UNITS_AND_PRICES) === $allowed)
            ->etc();

        $this->actingWith(['Product View List']);
        $this->get(route('products.tree'))->assertOk()->assertInertia($treeAllows(false));
        $this->get($url)->assertForbidden();

        $this->actingWith(['Product View List', ItemModelPermissions::UNITS_AND_PRICES]);
        $this->get(route('products.tree'))->assertOk()->assertInertia($treeAllows(true));
        $this->get($url)->assertOk();
    }
}
