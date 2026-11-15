<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\ProductionOrder;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Enums\ProductionOrderStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\tests\Unit\ConsolidatedTestFactory;
use Modules\Core\Entities\User;
use Modules\Items\Enums\ItemType;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * شاشات قسم الإنتاج في لوحة Vue: القائمة، الاضافة من طلبية بيع، التعديل مع معاينة التكلفة،
 * ومراحل الأمر (بدء، انهاء، اعادة فتح، الغاء، حذف) وكلٌّ بصلاحيته.
 */
class ProductionOrderPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['View List', 'View', 'Create', 'Update', 'Delete', 'Start', 'Complete', 'Cancel'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        foreach (self::PERMISSIONS as $action) {
            Permission::findOrCreate('Production Order '.$action, 'web');
        }

        Permission::findOrCreate('Sales Order Update', 'web');
    }

    private function signIn(?array $actions = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (string $action): string => 'Production Order '.$action, $actions ?? self::PERMISSIONS));
        $this->actingAs($user);

        return $user;
    }

    /**
     * طلبية بيع مؤكدة ببند واحد: 4 ابواب × 125.
     */
    private function salesOrder(): SalesOrder
    {
        $salesOrder = SalesOrder::create([
            'reference_no' => 'SO-'.Str::random(8),
            'client_id' => User::factory()->create(['name' => 'زبون الإنتاج'])->id,
            'order_date' => now()->toDateString(),
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'exchange_rate' => 1,
            'status' => SalesOrderStatus::CONFIRMED->value(),
            'total_amount' => 500,
        ]);
        $salesOrder->items()->create(['product_id' => ConsolidatedTestFactory::product()->id, 'description' => 'باب', 'quantity' => 4, 'unit_price' => 125, 'line_total' => 500]);

        return $salesOrder;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SalesOrder $salesOrder, array $overrides = []): array
    {
        $line = $salesOrder->items()->first();

        return array_replace([
            'sales_order_id' => $salesOrder->id,
            'order_date' => now()->toDateString(),
            'currency_id' => $salesOrder->currency_id,
            'produced_quantity' => 4,
            'materials' => [['sales_order_item_id' => $line->id, 'product_id' => $line->product_id, 'description' => 'باب', 'pricing_type' => 'simple', 'quantity' => 4, 'weight' => 0, 'unit_cost' => 8]],
            'labor' => [['worker_name' => 'عامل', 'description' => 'تجميع', 'rate_basis' => 'hour', 'quantity' => 10, 'rate' => 15]],
            'services' => [],
        ], $overrides);
    }

    public function test_production_pages_render_save_and_move_through_stages(): void
    {
        $this->signIn();
        $salesOrder = $this->salesOrder();

        $this->get(route('accounting.production-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Accounting/ProductionOrder/Index')->has('table.data', 0));

        // "إنشاء أمر إنتاج" من طلبية البيع: الطلبية مختارة وبنودها مقترحة.
        $this->get(route('accounting.production-orders.add', ['sales_order_id' => $salesOrder->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/ProductionOrder/Add')
                ->where('order.sales_order_id', $salesOrder->id)
                ->where('context.client_label', 'زبون الإنتاج')
                ->has('order.materials', 1)
                ->where('order.materials.0.description', 'باب'));

        $this->post(route('accounting.production-orders.store'), $this->payload($salesOrder))->assertRedirect();

        $order = ProductionOrder::query()->firstOrFail();

        // اصناف: 4 × 8 = 32؛ أجور: 10 × 15 = 150.
        $this->assertSame(182.0, (float) $order->total_cost);
        $this->assertSame(45.5, (float) $order->unit_cost);
        $this->assertSame(500.0, (float) $order->sales_amount);
        $this->assertSame($salesOrder->client_id, $order->client_id);

        $this->get(route('accounting.production-orders.edit', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/ProductionOrder/Edit')
                ->where('record.reference_no', $order->reference_no)
                ->where('record.can_start', true)
                ->has('order.materials', 1)
                ->has('order.labor', 1));

        // البدء يحفظ النموذج اولاً (أجر جديد مقطوع) ثم ينقل الطلبية الى "قيد التحضير".
        $this->post(route('accounting.production-orders.start', $order), $this->payload($salesOrder, [
            'labor' => [['worker_name' => 'عامل', 'description' => 'تركيب', 'rate_basis' => 'fixed', 'quantity' => 9, 'rate' => 50]],
        ]))->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(ProductionOrderStatus::IN_PROGRESS, $order->status);
        $this->assertSame(82.0, (float) $order->total_cost, 'Lump-sum wage counts once: 32 + 50.');
        $this->assertSame(SalesOrderStatus::PREPARING, $salesOrder->refresh()->status);

        $this->post(route('accounting.production-orders.complete', $order))->assertSessionHas('success');
        $this->assertSame(ProductionOrderStatus::COMPLETED, $order->refresh()->status);

        // المكتمل للقراءة فقط: التعديل يُرفض، ويُعاد فتحه.
        $this->put(route('accounting.production-orders.update', $order), $this->payload($salesOrder))->assertSessionHas('error');
        $this->post(route('accounting.production-orders.reopen', $order))->assertSessionHas('success');
        $this->assertSame(ProductionOrderStatus::IN_PROGRESS, $order->refresh()->status);

        $this->post(route('accounting.production-orders.cancel', $order))->assertSessionHas('success');
        $this->assertSame(ProductionOrderStatus::CANCELLED, $order->refresh()->status);

        // الملغى لا يُحذف (الحذف للمسودة فقط).
        $this->delete(route('accounting.production-orders.destroy', $order))->assertSessionHas('error');
        $this->assertNotNull(ProductionOrder::query()->find($order->id));
    }

    public function test_cost_preview_matches_saving_and_the_form_endpoints_answer(): void
    {
        $this->signIn();
        $salesOrder = $this->salesOrder();
        $weight = ConsolidatedTestFactory::product('weight');
        $service = ConsolidatedTestFactory::product();
        $service->update(['item_type' => ItemType::SERVICE->value, 'pricing_type' => 'weight', 'cost_price' => 3]);

        $this->postJson(route('accounting.production-orders.cost-preview'), [
            'sales_order_id' => $salesOrder->id,
            'produced_quantity' => 2,
            'materials' => [
                ['pricing_type' => 'simple', 'quantity' => 4, 'unit_cost' => 8],
                ['pricing_type' => 'weight', 'quantity' => 1, 'weight' => 12.5, 'unit_cost' => 2],
            ],
            'labor' => [['rate_basis' => 'fixed', 'quantity' => 7, 'rate' => 40]],
            'services' => [['quantity' => 3, 'unit_cost' => 5]],
        ])
            ->assertOk()
            ->assertJsonPath('lines.materials', [32, 25])
            ->assertJsonPath('lines.labor', [40])
            ->assertJsonPath('summary.total_cost', 112)
            ->assertJsonPath('summary.unit_cost', 56)
            ->assertJsonPath('summary.sales_amount', 500)
            ->assertJsonPath('summary.margin', 388)
            ->assertJsonPath('materials_weight', 12.5);

        $this->getJson(route('accounting.production-orders.sales-order', ['sales_order_id' => $salesOrder->id]))
            ->assertOk()
            ->assertJsonPath('client_label', 'زبون الإنتاج')
            ->assertJsonCount(1, 'materials');

        $this->getJson(route('accounting.production-orders.material', ['product_id' => $weight->id]))
            ->assertOk()
            ->assertJsonPath('pricing_type', 'weight')
            ->assertJsonPath('unit_cost', '8.0000');

        // خدمة موزونة تقترح كمية بوزن الاصناف الموزونة في الأمر.
        $this->postJson(route('accounting.production-orders.service'), [
            'product_id' => $service->id,
            'materials' => [['pricing_type' => 'weight', 'weight' => 12.5]],
        ])->assertOk()->assertJsonPath('pricing_basis', 'weight')->assertJsonPath('quantity', '12.5000');

        $this->getJson(route('accounting.production-orders.sales-orders', ['q' => $salesOrder->reference_no]))
            ->assertOk()
            ->assertJsonPath('0.value', $salesOrder->id);

        $this->postJson(route('accounting.production-orders.providers.store'), ['name' => 'مدهنة الشمال'])->assertOk()->assertJsonPath('label', 'مدهنة الشمال');
        $this->getJson(route('accounting.production-orders.providers', ['q' => 'مدهنة']))->assertOk()->assertJsonPath('0.label', 'مدهنة الشمال');
    }

    public function test_external_service_keeps_its_provider_and_an_internal_one_drops_it(): void
    {
        $this->signIn();
        $salesOrder = $this->salesOrder();
        $provider = User::factory()->create();

        $this->post(route('accounting.production-orders.store'), $this->payload($salesOrder, [
            'services' => [
                ['description' => 'دهان', 'cost_source' => 'external', 'service_provider_id' => $provider->id, 'pricing_basis' => 'quantity', 'quantity' => 2, 'unit_cost' => 10, 'sent_date' => now()->toDateString()],
                ['description' => 'تغليف', 'cost_source' => 'internal', 'service_provider_id' => $provider->id, 'pricing_basis' => 'quantity', 'quantity' => 1, 'unit_cost' => 5],
            ],
        ]))->assertRedirect();

        $services = ProductionOrder::query()->firstOrFail()->services;

        $this->assertSame($provider->id, $services[0]->service_provider_id);
        $this->assertNull($services[1]->service_provider_id);
        $this->assertSame(207.0, (float) ProductionOrder::query()->value('total_cost'));
    }

    public function test_a_draft_order_is_deleted_and_validation_is_shown(): void
    {
        $this->signIn();
        $salesOrder = $this->salesOrder();

        $this->post(route('accounting.production-orders.store'), $this->payload($salesOrder, ['produced_quantity' => 0]))->assertSessionHasErrors('produced_quantity');

        $this->post(route('accounting.production-orders.store'), $this->payload($salesOrder))->assertRedirect();
        $order = ProductionOrder::query()->firstOrFail();

        $this->delete(route('accounting.production-orders.destroy', $order))->assertRedirect(route('accounting.production-orders.index'));
        $this->assertSoftDeleted($order);
        $this->assertSame(0, $order->materials()->count());
    }

    public function test_every_action_needs_its_permission(): void
    {
        $salesOrder = $this->salesOrder();
        $this->signIn();
        $this->post(route('accounting.production-orders.store'), $this->payload($salesOrder));
        $order = ProductionOrder::query()->firstOrFail();

        $this->signIn(['View List']);

        $this->get(route('accounting.production-orders.index'))->assertOk();
        $this->get(route('accounting.production-orders.add'))->assertForbidden();
        $this->post(route('accounting.production-orders.store'), $this->payload($salesOrder))->assertForbidden();
        $this->get(route('accounting.production-orders.edit', $order))->assertForbidden();
        $this->post(route('accounting.production-orders.start', $order))->assertForbidden();
        $this->post(route('accounting.production-orders.complete', $order))->assertForbidden();
        $this->post(route('accounting.production-orders.cancel', $order))->assertForbidden();
        $this->delete(route('accounting.production-orders.destroy', $order))->assertForbidden();
        $this->postJson(route('accounting.production-orders.cost-preview'), [])->assertForbidden();
        $this->getJson(route('accounting.production-orders.materials'))->assertForbidden();

        // التعديل وحده يفتح نقاط النموذج.
        $this->signIn(['Update']);
        $this->getJson(route('accounting.production-orders.sales-orders'))->assertOk();
        $this->post(route('accounting.production-orders.start', $order))->assertForbidden();

        $this->signIn(['Start']);
        $this->post(route('accounting.production-orders.start', $order))->assertSessionHas('success');
        $this->assertSame(ProductionOrderStatus::IN_PROGRESS, $order->refresh()->status);
    }
}
