<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Services\PurchaseOrderService;
use Modules\Core\Entities\User;
use Modules\Items\Support\ProductSearch;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * طول الصنف داخل بنود طلبية الشراء.
 */
class PurchaseOrderItemLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_payload_exposes_the_length(): void
    {
        $product = ConsolidatedTestFactory::product('simple', 6.5);

        $payload = ProductSearch::invoiceItemPayload($product->id);

        $this->assertArrayHasKey('length', $payload);
        $this->assertSame('6.5000', $payload['length']);
    }

    public function test_payload_length_is_null_when_the_product_has_none(): void
    {
        $product = ConsolidatedTestFactory::product('simple');

        $payload = ProductSearch::invoiceItemPayload($product->id);

        $this->assertNull($payload['length']);
    }

    public function test_purchase_order_item_persists_the_length(): void
    {
        $product = ConsolidatedTestFactory::product('simple', 6.5);
        $order = ConsolidatedTestFactory::purchaseOrder();

        $item = $order->items()->create([
            'product_id' => $product->id,
            'description' => 'بند بطول',
            'quantity' => 2,
            'length' => ProductSearch::invoiceItemPayload($product->id)['length'],
            'unit_price' => 8,
            'line_total' => 16,
        ]);

        $this->assertSame(6.5, (float) $item->refresh()->length);
    }

    public function test_length_is_nullable(): void
    {
        $product = ConsolidatedTestFactory::product('simple');
        $order = ConsolidatedTestFactory::purchaseOrder();

        $item = $order->items()->create([
            'product_id' => $product->id,
            'description' => 'بند بلا طول',
            'quantity' => 1,
            'length' => null,
            'unit_price' => 8,
            'line_total' => 8,
        ]);

        $this->assertNull($item->refresh()->length);
    }

    /**
     * عمود الطول في جدول اصناف طلبية الشراء (Vue: showLength): الصنف المختار يأتي بطوله من
     * نقطة الجدول، والسطر المحفوظ يعود بطوله الى صفحة التعديل.
     */
    public function test_purchase_order_grid_carries_the_length(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('Purchase Order Create', 'web'));
        $this->actingAs($user);

        $product = ConsolidatedTestFactory::product('simple', 6.5);

        $payload = $this->getJson(route('accounting.items-grid.item', ['document' => 'purchase-order', 'product_id' => $product->id]))
            ->assertOk()
            ->json();

        $this->assertSame('6.5000', $payload['length'], 'طلبية الشراء يجب ان تعرض الطول.');

        $order = ConsolidatedTestFactory::purchaseOrder();
        $order->items()->create([
            'product_id' => $product->id,
            'description' => 'بند بطول',
            'quantity' => 2,
            'length' => $payload['length'],
            'unit_price' => 8,
            'line_total' => 16,
        ]);

        $rows = app(PurchaseOrderService::class)->gridRows($order->refresh());

        $this->assertSame(6.5, (float) $rows[0]['length']);
    }

    public function test_purchase_order_grid_rows_carry_the_optional_price(): void
    {
        $product = ConsolidatedTestFactory::product();
        $order = ConsolidatedTestFactory::purchaseOrder();
        $order->items()->create([
            'product_id' => $product->id,
            'description' => 'بند بسعر',
            'quantity' => 2,
            'unit_price' => 8,
            'line_total' => 16,
        ]);

        $row = app(PurchaseOrderService::class)->gridRows($order->refresh())[0];

        // طلبية الشراء بسعر اختياري: يظهر السعر والاجمالي في بنودها.
        $this->assertSame(8.0, (float) $row['unit_price']);
        $this->assertSame(16.0, (float) $row['line_total']);
    }
}
