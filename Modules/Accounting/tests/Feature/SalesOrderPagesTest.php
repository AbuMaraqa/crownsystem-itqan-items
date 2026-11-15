<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\SalesQuotation;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Enums\SalesQuotationStatus;
use Modules\Accounting\Services\SalesQuotationService;
use Modules\Accounting\tests\Feature\Concerns\SalesDocumentFixtures;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * طلبيات البيع في لوحة Vue: القائمة وفلاترها، الاضافة (يدوياً ومن عرض سعر) بسطور جدول الاصناف
 * والخدمات ومواد العميل، والتعديل، والتأكيد والتجهيز والفوترة والالغاء والحذف (كل اجراء يحفظ
 * النموذج اولاً)، والتوريد المباشر، وكل اجراء بصلاحيته.
 */
class SalesOrderPagesTest extends TestCase
{
    use RefreshDatabase;
    use SalesDocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSalesDocuments();
    }

    public function test_the_list_shows_orders_with_filters(): void
    {
        $order = $this->draftOrder();

        $this->get(route('accounting.sales-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/SalesOrder/Index')
                ->has('table.data', 1)
                ->where('table.data.0.reference_no', $order->reference_no)
                ->where('table.data.0.client.name', 'زبون المبيعات')
                ->where('table.data.0.sales_quotation', null)
                ->where('table.data.0.can_delete', true)
                ->has('statusOptions', 5));

        $this->get(route('accounting.sales-orders.index', ['filter' => ['status' => SalesOrderStatus::CANCELLED->value()]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 0));
        $this->get(route('accounting.sales-orders.index', ['filter' => ['source_state' => 'manual', 'items_state' => 'with_items', 'client' => 'المبيعات']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1));
        $this->get(route('accounting.sales-orders.index', ['filter' => ['source_state' => 'from_quotation']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 0));
        $this->get(route('accounting.sales-orders.index', ['filter' => ['delivery_state' => 'no_date', 'total_amount_from' => 1]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1));
    }

    public function test_an_order_is_created_with_lines_services_and_customer_materials(): void
    {
        $this->get(route('accounting.sales-orders.add'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/SalesOrder/Add')
                ->where('order.items', [])
                ->where('order.fulfillment_type', 'warehouse')
                ->has('serviceOptions.pricing_bases', 3)
                ->has('fulfillmentOptions', 2)
                ->has('referenceNo'));

        $ore = $this->newProduct('خام ore');
        $service = $this->serviceProduct();

        $this->post(route('accounting.sales-orders.store'), $this->orderData([$this->weightLine(2, 20)], [
            'services' => [$this->serviceLine($service)],
            'customer_materials' => [['product_id' => $ore->id, 'description' => null, 'quantity' => 1, 'weight' => 500, 'received_date' => now()->toDateString()]],
        ]))->assertRedirect()->assertSessionHas('success');

        $order = SalesOrder::query()->latest('id')->firstOrFail()->load(['items.pieces', 'customerMaterials']);
        $goods = $order->items->firstWhere('line_type', 'goods');
        $serviceLine = $order->items->firstWhere('line_type', 'service');

        // 2 × 6 × 1.2 = 14.4 كغم × 20 = 288، والخدمة 500 × 0.2 = 100 بتكلفة 40.
        $this->assertSame(288.0, (float) $goods->line_total);
        $this->assertCount(1, $goods->pieces);
        $this->assertSame(40.0, (float) $serviceLine->cost_total);
        $this->assertSame(388.0, (float) $order->total_amount);
        $this->assertSame('خام ore', $order->customerMaterials->sole()->description);
        $this->assertSame(SalesOrderStatus::DRAFT, $order->status);
    }

    public function test_a_new_main_item_without_sub_items_or_a_goods_line_as_a_service_is_refused(): void
    {
        $line = $this->weightLine();
        $line['pieces'] = [];

        $this->post(route('accounting.sales-orders.store'), $this->orderData([$line]))->assertSessionHas('error');

        $goodsAsService = $this->serviceLine($this->newProduct('سلعة'));
        $this->post(route('accounting.sales-orders.store'), $this->orderData([$this->weightLine()], ['services' => [$goodsAsService]]))->assertSessionHas('error');

        $this->assertSame(0, SalesOrder::query()->count());
    }

    public function test_the_edit_page_shows_services_and_materials_and_keeps_them_on_save(): void
    {
        $service = $this->serviceProduct();
        $order = $this->draftOrder([$this->weightLine()], [
            'services' => [$this->serviceLine($service)],
            'customer_materials' => [['description' => 'خام', 'quantity' => 1, 'weight' => 500]],
        ]);
        $serviceLine = $order->items->firstWhere('line_type', 'service');
        $goodsLine = $order->items->firstWhere('line_type', 'goods');

        $response = $this->get(route('accounting.sales-orders.edit', $order->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/SalesOrder/Edit')
                ->where('record.editable', true)
                ->where('record.can_confirm', true)
                ->where('record.can_invoice', true)
                ->where('record.delivery.can_deliver', false)
                ->has('order.items', 1)
                ->where('order.items.0.id', $goodsLine->id)
                ->where('order.services.0.id', $serviceLine->id)
                ->where('order.services.0.cost_source', 'internal')
                ->where('order.customer_materials.0.description', 'خام'));

        $form = $this->props($response)['order'];
        $this->assertEquals(0.08, $form['services'][0]['unit_cost']);
        $this->assertEquals(500, $form['customer_materials'][0]['weight']);

        $form['notes'] = 'محدثة';
        $this->put(route('accounting.sales-orders.update', $order->id), $form)->assertSessionHas('success');

        $order->refresh()->load(['items', 'customerMaterials']);
        $this->assertSame('محدثة', $order->notes);
        $this->assertSame($serviceLine->id, $order->items->firstWhere('line_type', 'service')->id);
        $this->assertSame(40.0, (float) $order->items->firstWhere('line_type', 'service')->cost_total);
        $this->assertSame($goodsLine->id, $order->items->firstWhere('line_type', 'goods')->id);
        $this->assertCount(1, $order->customerMaterials);
    }

    public function test_an_order_from_a_quotation_takes_its_lines_and_converts_it(): void
    {
        $quotationService = app(SalesQuotationService::class);
        $quotation = $quotationService->approve($quotationService->create([
            'client_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => [$this->weightLine(2, 20), ['product_id' => $this->serviceProduct()->id, 'quantity' => 100, 'unit_price' => 0.2]],
        ]));

        $this->getJson(route('accounting.sales-orders.quotations', ['client_id' => $this->customer->id]))
            ->assertOk()
            ->assertJsonPath('0.value', $quotation->id);

        $response = $this->get(route('accounting.sales-orders.add', ['sales_quotation_id' => $quotation->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.sales_quotation_id', $quotation->id)
                ->where('quotation.label', $quotation->reference_no)
                ->where('client.value', $this->customer->id)
                ->has('order.items', 1)
                ->where('order.items.0.id', null)
                ->has('order.items.0.pieces', 1)
                ->has('order.services', 1));

        $this->getJson(route('accounting.sales-orders.quotation-defaults', ['sales_quotation_id' => $quotation->id]))
            ->assertOk()
            ->assertJsonPath('client.value', $this->customer->id)
            ->assertJsonCount(1, 'items');

        $form = $this->props($response)['order'];
        $this->post(route('accounting.sales-orders.store'), $form)->assertSessionHas('success');

        $order = SalesOrder::query()->where('sales_quotation_id', $quotation->id)->sole();
        $this->assertCount(2, $order->items);
        $this->assertNotNull($order->items->firstWhere('line_type', 'goods')->sales_quotation_item_id);
        $this->assertSame(SalesQuotationStatus::CONVERTED, $quotation->refresh()->status);

        $this->get(route('accounting.sales-orders.edit', $order->id))
            ->assertInertia(fn (Assert $page) => $page->where('record.sales_quotation.id', $quotation->id));

        // حذف الطلبية يعيد العرض معتمداً.
        $this->delete(route('accounting.sales-orders.destroy', $order->id))->assertRedirect(route('accounting.sales-orders.index'));
        $this->assertSoftDeleted($order);
        $this->assertSame(SalesQuotationStatus::APPROVED, $quotation->refresh()->status);
    }

    public function test_confirm_saves_the_form_first_and_notifies_purchasing_for_direct_supply(): void
    {
        $supplier = User::factory()->create(['name' => 'مورد مباشر']);
        $supplier->assignRole(Role::findOrCreate('Supplier', 'web'));
        $order = $this->draftOrder();

        $this->getJson(route('accounting.sales-orders.suppliers', ['q' => 'مباشر']))->assertOk()->assertJsonPath('0.value', $supplier->id);

        $form = $this->props($this->get(route('accounting.sales-orders.edit', $order->id)))['order'];
        $form['fulfillment_type'] = 'direct';
        $form['direct_supplier_id'] = $supplier->id;

        $this->post(route('accounting.sales-orders.confirm', $order->id), $form)->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(SalesOrderStatus::CONFIRMED, $order->status);
        $this->assertSame($supplier->id, $order->direct_supplier_id);
        $this->assertNotNull($order->purchasing_notified_at);

        $this->get(route('accounting.sales-orders.edit', $order->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.direct_supply.supplier', 'مورد مباشر')
                ->where('record.direct_supply.can_create_purchase_order', true)
                ->where('record.delivery.is_direct', true)
                ->where('record.can_confirm', false));
    }

    public function test_prepare_invoice_and_cancel(): void
    {
        $order = $this->draftOrder();

        $this->post(route('accounting.sales-orders.prepare', $order->id))->assertSessionHas('success');
        $this->assertSame(SalesOrderStatus::PREPARING, $order->refresh()->status);

        $this->get(route('accounting.sales-orders.edit', $order->id))
            ->assertInertia(fn (Assert $page) => $page->where('record.delivery.can_deliver', true)->where('record.delivery.percent', 0));

        $this->post(route('accounting.sales-orders.invoice', $order->id))->assertRedirect()->assertSessionHas('success');
        $invoice = SalesInvoice::query()->where('sales_order_id', $order->id)->sole();
        $this->assertCount(1, $invoice->items()->sole()->pieces);
        $this->assertSame(SalesOrderStatus::INVOICED, $order->refresh()->status);

        // المفوترة نهائية: للقراءة، ولا الغاء.
        $this->get(route('accounting.sales-orders.edit', $order->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.editable', false)
                ->where('record.can_invoice', false)
                ->where('record.sales_invoices.0.id', $invoice->id));
        $this->post(route('accounting.sales-orders.cancel', $order->id))->assertSessionHas('error');

        $other = $this->draftOrder();
        $this->post(route('accounting.sales-orders.cancel', $other->id))->assertSessionHas('success');
        $this->assertSame(SalesOrderStatus::CANCELLED, $other->refresh()->status);
    }

    public function test_a_refused_action_keeps_the_saved_form_unchanged(): void
    {
        $order = $this->draftOrder();
        $form = $this->props($this->get(route('accounting.sales-orders.edit', $order->id)))['order'];
        $form['notes'] = 'لن تُحفظ';
        $form['items'] = [];

        // طلبية بلا بنود لا تُؤكد، والحفظ قبلها يتراجع معها.
        $this->post(route('accounting.sales-orders.confirm', $order->id), $form)->assertSessionHas('error');

        $order->refresh();
        $this->assertSame(SalesOrderStatus::DRAFT, $order->status);
        $this->assertSame('ملاحظة الطلبية', $order->notes);
        $this->assertCount(1, $order->items);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $order = $this->draftOrder();

        $this->signIn(['Sales Order View List']);
        $this->get(route('accounting.sales-orders.index'))->assertOk();
        $this->get(route('accounting.sales-orders.add'))->assertForbidden();
        $this->post(route('accounting.sales-orders.store'), $this->orderData())->assertForbidden();
        $this->get(route('accounting.sales-orders.edit', $order->id))->assertForbidden();
        $this->put(route('accounting.sales-orders.update', $order->id), $this->orderData())->assertForbidden();
        $this->post(route('accounting.sales-orders.confirm', $order->id))->assertForbidden();
        $this->post(route('accounting.sales-orders.prepare', $order->id))->assertForbidden();
        $this->post(route('accounting.sales-orders.invoice', $order->id))->assertForbidden();
        $this->post(route('accounting.sales-orders.cancel', $order->id))->assertForbidden();
        $this->delete(route('accounting.sales-orders.destroy', $order->id))->assertForbidden();
        $this->get(route('accounting.sales-orders.pdf', $order->id))->assertForbidden();
        $this->getJson(route('accounting.items-grid.services', ['document' => 'sales-order']))->assertForbidden();

        $this->signIn([]);
        $this->get(route('accounting.sales-orders.index'))->assertForbidden();
        $this->assertSame(SalesOrderStatus::DRAFT, $order->refresh()->status);
    }

    /**
     * يولّد PDF (واجهة PDF العامة): آخر اختبار في الصنف، انظر تقرير مسار pdf في web.php.
     */
    public function test_info_and_pdf(): void
    {
        $order = $this->draftOrder();

        $this->getJson(route('accounting.sales-orders.info', $order->id))->assertOk();
        $this->get(route('accounting.sales-orders.pdf', $order->id))->assertOk();
    }
}
