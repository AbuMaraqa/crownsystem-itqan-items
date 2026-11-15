<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Entities\User;
use Modules\Items\Entities\ProductAccessory;
use Modules\Items\Enums\ItemType;
use Tests\TestCase;

/**
 * ملحقات الصنف: تُعرّف في بطاقة الصنف، وتُقترح (لا تُفرض) عند اختيار الصنف
 * في طلبية البيع. حفظ الملحقات من بطاقة الصنف في
 * Modules/Items/tests/Feature/ProductPagesTest.php::test_accessories_are_saved_in_order_and_never_the_item_itself.
 */
class ProductAccessoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_picking_an_item_in_a_sales_order_suggests_its_accessories(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
        ]);
        $user = User::factory()->create();
        $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('Sales Order Create', 'web'));
        $this->actingAs($user);

        $window = ConsolidatedTestFactory::product();
        $handle = ConsolidatedTestFactory::product();
        $installation = ConsolidatedTestFactory::product();
        $installation->update(['item_type' => ItemType::SERVICE->value]);

        ProductAccessory::create(['product_id' => $window->id, 'accessory_product_id' => $handle->id, 'quantity' => 2]);
        ProductAccessory::create(['product_id' => $window->id, 'accessory_product_id' => $installation->id, 'quantity' => 1]);

        // الصنف كما يدخل جدول اصناف طلبية البيع (Vue)؛ بلا زبون لا سعر تلقائي.
        $payload = $this->getJson(route('accounting.items-grid.item', [
            'document' => 'sales-order',
            'product_id' => $window->id,
            'party_id' => User::factory()->create()->id,
        ]))->assertOk()->json();

        // الخدمة لها قسمها في الطلبية فلا تُقترح في جدول الاصناف.
        $this->assertCount(1, $payload['accessories']);
        $this->assertSame('2.0000', $payload['accessories'][0]['quantity']);
        $this->assertSame($handle->id, $payload['accessories'][0]['product']['id']);
        $this->assertSame('12.0000', $payload['accessories'][0]['product']['unit_price']);

        // ملحقات الملحق لا تُجلب، ولا في قطعة داخل صنف رئيسي.
        $this->assertArrayNotHasKey('accessories', $payload['accessories'][0]['product']);
    }

    public function test_purchase_orders_do_not_suggest_accessories(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
        ]);
        $user = User::factory()->create();
        $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('Purchase Order Create', 'web'));
        $this->actingAs($user);

        $window = ConsolidatedTestFactory::product();
        $handle = ConsolidatedTestFactory::product();
        ProductAccessory::create(['product_id' => $window->id, 'accessory_product_id' => $handle->id, 'quantity' => 2]);

        // الصنف كما يدخل جدول اصناف طلبية الشراء (Vue).
        $payload = $this->getJson(route('accounting.items-grid.item', [
            'document' => 'purchase-order',
            'product_id' => $window->id,
        ]))->assertOk()->json();

        $this->assertSame($window->id, $payload['id']);

        $this->assertArrayNotHasKey('accessories', $payload);
    }
}
