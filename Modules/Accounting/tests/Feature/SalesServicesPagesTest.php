<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\SalesOrderService;
use Modules\Accounting\tests\Feature\Concerns\SalesDocumentFixtures;
use Modules\Core\Entities\User;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\ProductPrice;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * صفحتا الطلبية والفاتورة: الخدمات في قسمها لا في جدول الاصناف، ومواد العميل تُعرض وتبقى بعد
 * الحفظ، والحفظ لا يُسقط تكلفة الخدمة او جهتها. ونقاط قسم الخدمات في جدول الاصناف: البحث،
 * سعر الخدمة للطرف وتكلفتها، الجهات المنفذة وانشاء جهة جديدة، ومجاميع المستند مع الخدمات.
 */
class SalesServicesPagesTest extends TestCase
{
    use RefreshDatabase;
    use SalesDocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSalesDocuments();
    }

    public function test_order_page_shows_services_and_customer_materials_and_keeps_them_on_save(): void
    {
        $order = $this->orderWithService();
        $serviceLine = $order->items->firstWhere('line_type', 'service');

        $response = $this->get(route('accounting.sales-orders.edit', $order->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.services.0.id', $serviceLine->id)
                ->where('order.customer_materials.0.description', 'خام ore'));

        $form = $this->props($response)['order'];
        $this->assertEquals($serviceLine->unit_cost, $form['services'][0]['unit_cost']);
        $this->assertEquals(500, $form['customer_materials'][0]['weight']);
        // جدول الاصناف لا يحمل بند الخدمة.
        $this->assertSame([], $form['items']);

        $this->put(route('accounting.sales-orders.update', $order->id), $form)->assertSessionHas('success');

        $order->refresh()->load(['items', 'customerMaterials']);
        $line = $order->items->firstWhere('line_type', 'service');

        $this->assertNotNull($line);
        $this->assertSame(0.08, (float) $line->unit_cost);
        $this->assertSame(40.0, (float) $line->cost_total);
        $this->assertSame(100.0, (float) $order->total_amount);
        $this->assertCount(1, $order->customerMaterials);
    }

    public function test_invoice_page_lists_service_lines_in_the_services_section(): void
    {
        $invoice = app(SalesInvoiceService::class)->createFromOrder($this->orderWithService());
        $serviceLine = $invoice->items->firstWhere('line_type', 'service');

        $response = $this->get(route('accounting.sales-invoices.edit', $invoice->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoice.services.0.id', $serviceLine->id)
                ->where('invoice.services.0.cost_source', 'internal')
                ->where('record.materials_weight', 500));

        $this->put(route('accounting.sales-invoices.update', $invoice->id), $this->props($response)['invoice'])->assertSessionHas('success');

        $this->assertSame(40.0, (float) $invoice->items()->where('line_type', 'service')->value('cost_total'));
        $this->assertSame(100.0, (float) $invoice->refresh()->total_amount);
    }

    public function test_the_services_section_endpoints(): void
    {
        $service = $this->serviceProduct();
        $goods = $this->newProduct('خام ore');
        $list = new PriceList(['same_buy_sell' => false, 'is_active' => true, 'sort_order' => 1]);
        $list->save();
        $list->translateOrNew(app()->getLocale())->name = 'قائمة الخدمات';
        $list->save();
        ProductPrice::create(['price_list_id' => $list->id, 'product_id' => $service->id, 'sell_price' => 0.35, 'buy_price' => 0]);
        $this->customer->update(['price_list_id' => $list->id]);

        $this->getJson(route('accounting.items-grid.services', ['document' => 'sales-order', 'q' => 'معالجة']))
            ->assertOk()
            ->assertJsonPath('0.value', $service->id);
        $this->getJson(route('accounting.items-grid.goods', ['document' => 'sales-order', 'q' => 'ore']))
            ->assertOk()
            ->assertJsonPath('0.value', $goods->id);

        // السعر للطرف، والتكلفة واساس التسعير ومصدر التكلفة من بطاقة الخدمة.
        $this->getJson(route('accounting.items-grid.service', ['document' => 'sales-order', 'product_id' => $service->id, 'party_id' => $this->customer->id]))
            ->assertOk()
            ->assertJsonPath('unit_price', '0.3500')
            ->assertJsonPath('unit_cost', '0.0800')
            ->assertJsonPath('pricing_basis', 'weight')
            ->assertJsonPath('cost_source', 'internal');
        $this->assertSame('null', $this->getJson(route('accounting.items-grid.service', ['document' => 'sales-order', 'product_id' => $goods->id]))->getContent());

        $supplier = User::factory()->create(['name' => 'ناقل خارجي']);
        $supplier->assignRole(Role::findOrCreate('Delivery Company', 'web'));
        $this->getJson(route('accounting.items-grid.service-providers', ['document' => 'sales-invoice', 'q' => 'ناقل']))
            ->assertOk()
            ->assertJsonPath('0.value', $supplier->id);

        $this->postJson(route('accounting.items-grid.service-providers.store', ['document' => 'sales-order']), ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name', 'data');
        $created = $this->postJson(route('accounting.items-grid.service-providers.store', ['document' => 'sales-order']), ['name' => 'مورد جديد', 'phone' => '0599'])
            ->assertOk()
            ->json('value');
        $this->assertTrue(User::query()->findOrFail($created)->hasRole('Supplier'));
    }

    public function test_the_grid_totals_count_the_service_lines(): void
    {
        $service = $this->serviceProduct();

        $this->postJson(route('accounting.items-grid.totals', ['document' => 'sales-order']), [
            'items' => [array_merge($this->weightLine(2, 20), ['key' => 'a'])],
            'extra_lines' => [['key' => 's', 'product_id' => $service->id, 'quantity' => 500, 'unit_price' => 0.2, 'discount_mode' => 'rate', 'discount_rate' => 10]],
            'document_discount' => 0,
            'tax_rate' => 0,
        ])
            ->assertOk()
            // 288 + (100 − 10%).
            ->assertJsonPath('totals.sub_total', 388)
            ->assertJsonPath('totals.items_discount', 10)
            ->assertJsonPath('totals.total_amount', 378)
            ->assertJsonPath('extra_lines.0.line_total', 90);

        // بلا بنود خارجية يبقى الجواب كما كان.
        $this->postJson(route('accounting.items-grid.totals', ['document' => 'sales-quotation']), ['items' => [array_merge($this->weightLine(2, 20), ['key' => 'a'])]])
            ->assertOk()
            ->assertJsonMissingPath('extra_lines')
            ->assertJsonPath('totals.total_amount', 288);
    }

    private function orderWithService(): SalesOrder
    {
        $ore = $this->newProduct('خام ore');
        $service = $this->serviceProduct();

        return app(SalesOrderService::class)->create([
            'client_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => [
                ['product_id' => $service->id, 'quantity' => 500, 'unit_price' => 0.2, 'unit_cost' => 0.08],
            ],
            'customer_materials' => [
                ['product_id' => $ore->id, 'quantity' => 1, 'weight' => 500],
            ],
        ]);
    }
}
