<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Enums\PurchaseOrderDeliveryType;
use Modules\Accounting\Enums\SalesOrderFulfillmentType;
use Modules\Accounting\Services\PurchaseOrderService;
use Modules\Accounting\Services\SalesOrderService;
use Modules\Accounting\Support\DirectPurchaseOrderDraft;
use Modules\Accounting\Support\PurchasingNotifier;
use Modules\Accounting\tests\Feature\Concerns\PriceGroupFixtures;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * طلبية البيع التي يوردها مورد آخر للزبون مباشرة تنتقل لقسم المشتريات عند تأكيدها:
 * اشعار لمن يملك صلاحية انشاء طلبية شراء، ورابطه يفتح طلبية شراء معبأة منها.
 */
class DirectSupplySalesOrderTest extends TestCase
{
    use PriceGroupFixtures;
    use RefreshDatabase;

    private User $seller;

    private User $purchaser;

    private User $otherUser;

    private User $client;

    private User $supplier;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => PurchasingNotifier::PERMISSION, 'guard_name' => 'web']);

        $this->seller = User::factory()->create();
        $this->purchaser = User::factory()->create();
        $this->purchaser->givePermissionTo(PurchasingNotifier::PERMISSION);
        $this->otherUser = User::factory()->create();
        $this->client = User::factory()->create();
        $this->supplier = User::factory()->create();

        $this->currency = Currency::create([
            'code' => 'ILS',
            'symbol' => '₪',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->actingAs($this->seller);
    }

    public function test_confirming_a_direct_supply_order_notifies_purchasing_once(): void
    {
        $order = $this->order(SalesOrderFulfillmentType::DIRECT);

        $this->assertSame(0, $this->purchasingNotifications($this->purchaser)->count(), 'drafts are not sent to purchasing');

        $service = app(SalesOrderService::class);
        $order = $service->confirm($order);

        $this->assertNotNull($order->purchasing_notified_at);
        $notifications = $this->purchasingNotifications($this->purchaser);
        $this->assertCount(1, $notifications);
        $this->assertStringContainsString('sales_order_id='.$order->id, $notifications->first()->data['url']);
        $this->assertSame($order->id, $notifications->first()->data['subject_id']);
        $this->assertCount(0, $this->purchasingNotifications($this->otherUser));
        $this->assertCount(0, $this->purchasingNotifications($this->seller));

        // الحفظ والانتقالات اللاحقة لا تكرر الاشعار.
        $service->update($order, $this->orderData(SalesOrderFulfillmentType::DIRECT));
        $service->sendToPreparation($order);
        $this->assertCount(1, $this->purchasingNotifications($this->purchaser));
    }

    public function test_warehouse_orders_are_not_sent_to_purchasing(): void
    {
        $order = app(SalesOrderService::class)->confirm($this->order(SalesOrderFulfillmentType::WAREHOUSE));

        $this->assertNull($order->purchasing_notified_at);
        $this->assertNull($order->direct_supplier_id);
        $this->assertCount(0, $this->purchasingNotifications($this->purchaser));
    }

    public function test_switching_a_confirmed_order_to_direct_supply_notifies_purchasing(): void
    {
        $service = app(SalesOrderService::class);
        $order = $service->confirm($this->order(SalesOrderFulfillmentType::WAREHOUSE));

        $service->update($order, $this->orderData(SalesOrderFulfillmentType::DIRECT));

        $this->assertCount(1, $this->purchasingNotifications($this->purchaser));
    }

    public function test_purchase_order_page_is_prefilled_from_the_sales_order(): void
    {
        $order = app(SalesOrderService::class)->confirm($this->order(SalesOrderFulfillmentType::DIRECT));
        $this->actingAs($this->purchaser);

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $data = $this->get(route('accounting.purchase-orders.add', ['sales_order_id' => $order->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Accounting/PurchaseOrder/Add')->where('supplier.value', $this->supplier->id))
            ->viewData('page')['props']['order'];
        $this->assertSame(PurchaseOrderDeliveryType::DIRECT->value, $data['delivery_type']);
        $this->assertSame($this->supplier->id, (int) $data['client_id']);
        $this->assertSame($this->client->id, (int) $data['direct_customer_id']);
        $this->assertSame($order->id, (int) $data['direct_sales_order_id']);
        $this->assertCount(1, $data['items']);
        $item = array_values($data['items'])[0];
        $this->assertSame(3.0, (float) $item['quantity']);
        $this->assertSame(0.0, (float) $item['unit_price'], 'purchase orders carry no prices; the supplier price comes in the purchase quotation');
    }

    public function test_a_price_group_line_goes_to_the_direct_purchase_order_with_its_colours(): void
    {
        ['white' => $white, 'whiteW45' => $whiteW45] = $this->alpineGroups();
        $data = array_replace($this->orderData(SalesOrderFulfillmentType::DIRECT), ['items' => [$this->groupLine($white, [[$whiteW45, 2]], 16)]]);
        $order = app(SalesOrderService::class)->confirm(app(SalesOrderService::class)->create($data));

        $draft = DirectPurchaseOrderDraft::state($order->load(['items.product', 'items.pieces.product']));
        $line = array_values($draft['items'])[0];
        $this->assertNull($line['product_id']);
        $this->assertSame($white->id, $line['category_finish_id']);
        $this->assertSame('weight', $line['pricing_type']);
        $this->assertSame('الوبين — أبيض', $line['product_label']);

        // الطلبية المحفوظة من المسودة تحمل السطر بمجموعته وقطعه.
        $this->actingAs($this->purchaser);
        $purchase = app(PurchaseOrderService::class)->create(array_replace($draft, ['order_date' => now()->toDateString()]));
        $saved = $purchase->items()->with('pieces')->sole();
        $this->assertSame($white->id, (int) $saved->category_finish_id);
        $this->assertSame([$whiteW45->id], $saved->pieces->pluck('product_id')->map(fn ($id) => (int) $id)->all());
    }

    private function purchasingNotifications(User $user)
    {
        return $user->notifications()->get()->filter(fn ($notification) => ($notification->data['event'] ?? null) === PurchasingNotifier::EVENT);
    }

    private function order(SalesOrderFulfillmentType $type): SalesOrder
    {
        return app(SalesOrderService::class)->create($this->orderData($type));
    }

    private function orderData(SalesOrderFulfillmentType $type): array
    {
        $this->product ??= $this->product();

        return [
            'client_id' => $this->client->id,
            'order_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'fulfillment_type' => $type->value,
            'direct_supplier_id' => $this->supplier->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 10],
            ],
        ];
    }

    private ?Product $product = null;

    private function product(): Product
    {
        $product = new Product([
            'slug' => 'test-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'base_price' => 10,
            'sale_price' => 10,
            'cost_price' => 6,
            'pricing_type' => 'simple',
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = 'صنف توريد مباشر';
        $product->save();

        return $product;
    }
}
