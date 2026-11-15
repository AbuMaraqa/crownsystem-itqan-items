<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Services\PurchaseOrderService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * الطلبية المفوترة تُفتح من رقمها في القائمة للاطلاع فقط، ولا يمكن تعديلها.
 */
class PurchaseOrderReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $this->withoutVite();

        Currency::create([
            'code' => 'ILS', 'symbol' => '₪', 'symbol_position' => 'before', 'decimal_places' => 2,
            'exchange_rate' => 1, 'is_active' => true, 'is_default' => true,
        ]);
    }

    public function test_invoiced_order_is_listed_and_opens_from_its_number(): void
    {
        $this->actingAsUserWith(['Purchase Order View List']);
        $order = $this->invoicedOrder();

        // الرقم يفتح صفحة الطلبية لكل سطر غير محذوف، والتعديل ليس متاحاً.
        $this->get(route('accounting.purchase-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/PurchaseOrder/Index')
                ->where('table.data.0.id', $order->id)
                ->where('table.data.0.deleted', false)
                ->where('table.data.0.editable', false));
    }

    public function test_invoiced_order_opens_read_only_even_for_a_user_who_can_update(): void
    {
        $this->actingAsUserWith(['Purchase Order View List', 'Purchase Order Update']);
        $order = $this->invoicedOrder();

        $this->get(route('accounting.purchase-orders.edit', $order->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/PurchaseOrder/Edit')
                ->where('record.editable', false)
                ->where('record.locked', true));
    }

    public function test_saving_an_invoiced_order_changes_nothing(): void
    {
        $this->actingAsUserWith(['Purchase Order View List', 'Purchase Order Update']);
        $order = $this->invoicedOrder();

        $this->put(route('accounting.purchase-orders.update', $order->id), $this->payload($order, ['notes' => 'تعديل ممنوع']))
            ->assertSessionHas('error');

        $this->assertNull($order->refresh()->notes);
    }

    public function test_user_without_update_permission_only_views_a_draft(): void
    {
        $this->actingAsUserWith(['Purchase Order View List']);
        $order = $this->draftOrder();

        $this->get(route('accounting.purchase-orders.edit', $order->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('record.editable', false)->where('record.locked', false));

        $this->put(route('accounting.purchase-orders.update', $order->id), $this->payload($order, ['notes' => 'x']))->assertForbidden();
    }

    public function test_user_with_update_permission_still_edits_a_draft(): void
    {
        $this->actingAsUserWith(['Purchase Order View List', 'Purchase Order Update']);
        $order = $this->draftOrder();

        $this->get(route('accounting.purchase-orders.edit', $order->id))
            ->assertInertia(fn (Assert $page) => $page->where('record.editable', true));

        $this->put(route('accounting.purchase-orders.update', $order->id), $this->payload($order, ['notes' => 'ملاحظة جديدة']))
            ->assertSessionHas('success');

        $this->assertSame('ملاحظة جديدة', $order->refresh()->notes);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actingAsUserWith(array $permissions): void
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(PurchaseOrder $order, array $data): array
    {
        $item = $order->items()->firstOrFail();

        return array_replace([
            'client_id' => $order->client_id,
            'order_date' => $order->order_date->toDateString(),
            'delivery_type' => 'warehouse',
            'items' => [['id' => $item->id, 'product_id' => $item->product_id, 'quantity' => 2, 'unit_price' => 0]],
        ], $data);
    }

    private function invoicedOrder(): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->markInvoiced($this->draftOrder());
    }

    private function draftOrder(): PurchaseOrder
    {
        $product = new Product([
            'slug' => 'pord-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => 'PORD-'.Str::random(6),
            'base_price' => 10,
            'cost_price' => 8,
            'sale_price' => 20,
            'pricing_type' => 'simple',
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = 'مقطع';
        $product->save();

        return app(PurchaseOrderService::class)->create([
            'client_id' => User::factory()->create()->id,
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
    }
}
