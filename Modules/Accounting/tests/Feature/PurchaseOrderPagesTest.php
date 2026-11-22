<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Enums\PurchaseOrderDeliveryType;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\tests\Feature\Concerns\PriceGroupFixtures;
use Modules\Accounting\tests\Feature\Concerns\PurchaseDocumentFixtures;
use Modules\Core\Entities\User;
use Modules\Items\Entities\ProductAccessory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * طلبيات الشراء في لوحة Vue: القائمة وفلاترها، الاضافة والتعديل بسطور جدول الاصناف (سطر وزن بقطعه
 * وسعر اختياري) والمرفقات، الشحن المباشر، والاجراءات (تأكيد، عرض سعر، تسليم للزبون، فاتورة،
 * الغاء، حذف) بصلاحياتها.
 */
class PurchaseOrderPagesTest extends TestCase
{
    use PriceGroupFixtures;
    use PurchaseDocumentFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPurchaseDocuments();
    }

    public function test_index_lists_and_filters_orders(): void
    {
        $order = $this->demoOrder('Partial receipt');

        $this->get(route('accounting.purchase-orders.index', ['filter' => ['reference_no' => $order->reference_no, 'status' => $order->status->value]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/PurchaseOrder/Index')
                ->has('table.data', 1)
                ->where('table.data.0.reference_no', $order->reference_no)
                ->where('table.data.0.client.name', $this->supplier()->name)
                ->has('statusOptions')
                ->has('deliveryTypeOptions'));

        $this->get(route('accounting.purchase-orders.index', ['filter' => ['item_barcode' => 'no-such-barcode']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 0));
    }

    public function test_an_order_is_added_with_a_weight_line_and_attachments_then_updated(): void
    {
        Storage::fake('accounting');

        $this->get(route('accounting.purchase-orders.add'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Accounting/PurchaseOrder/Add')->where('order.delivery_type', PurchaseOrderDeliveryType::WAREHOUSE->value));

        $this->post(route('accounting.purchase-orders.store'), [
            ...$this->header(),
            'items' => [
                $this->weightLine(8, 11),
                ['product_id' => $this->demoProduct('DEMO-HINGE')->id, 'quantity' => 10, 'unit_price' => 4, 'length' => 2.5],
            ],
            'attachments' => [UploadedFile::fake()->image('invoice.jpg')],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $order = PurchaseOrder::query()->latest('id')->with(['items.pieces'])->firstOrFail();
        $this->assertSame(PurchaseOrderStatus::DRAFT, $order->status);
        // 8 كغم فعلي × 11 = 88، و10 × 4 = 40.
        $this->assertSame(128.0, (float) $order->total_amount);
        $this->assertCount(1, $order->items->firstWhere('product_id', $this->demoProduct('DEMO-1025')->id)->pieces);
        $this->assertSame(2.5, (float) $order->items->firstWhere('product_id', $this->demoProduct('DEMO-HINGE')->id)->length);
        $this->assertCount(1, $order->getMedia('attachments'));

        $media = $order->getMedia('attachments')->first();
        $this->get(route('accounting.purchase-orders.edit', $order->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/PurchaseOrder/Edit')
                ->has('order.items', 2)
                ->has('attachments', 1)
                ->where('record.editable', true));

        $items = $order->items->map(fn ($item) => ['id' => $item->id, 'product_id' => $item->product_id, 'quantity' => 1, 'unit_price' => 4, 'actual_weight' => $item->actual_weight, 'pieces' => $item->pieces->map(fn ($piece) => ['id' => $piece->id, 'product_id' => $piece->product_id, 'piece_count' => $piece->piece_count, 'piece_length' => $piece->piece_length, 'weight_per_meter' => $piece->weight_per_meter])->all()])->all();

        $this->put(route('accounting.purchase-orders.update', $order->id), [
            ...$this->header(),
            'notes' => 'طلبية معدلة',
            'items' => $items,
            'removed_attachments' => [$media->id],
        ])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('طلبية معدلة', $order->notes);
        $this->assertCount(0, $order->getMedia('attachments'));
        // السطور نفسها بمعرفاتها، والمبلغ يُعاد حسابه: 8 × 4 + 1 × 4.
        $this->assertSame($order->items()->pluck('id')->sort()->values()->all(), collect($items)->pluck('id')->sort()->values()->all());
        $this->assertSame(36.0, (float) $order->total_amount);
    }

    public function test_a_new_main_item_without_sub_items_or_weight_is_refused(): void
    {
        $line = $this->weightLine();

        $this->post(route('accounting.purchase-orders.store'), [...$this->header(), 'items' => [array_replace($line, ['pieces' => []])]])
            ->assertSessionHas('error');

        $line['pieces'][0]['piece_length'] = 0;
        $this->post(route('accounting.purchase-orders.store'), [...$this->header(), 'items' => [$line]])
            ->assertSessionHas('error');

        $this->assertSame(0, PurchaseOrder::query()->where('notes', 'جديدة')->count());
    }

    public function test_a_price_group_line_is_saved_and_reopened(): void
    {
        ['white' => $white, 'whiteW45' => $w45, 'meshW45' => $meshW45] = $this->alpineGroups();

        $this->post(route('accounting.purchase-orders.store'), [...$this->header(), 'items' => [$this->groupLine($white, [[$meshW45, 1]], 8, 0)]])
            ->assertSessionHas('error');
        $this->post(route('accounting.purchase-orders.store'), [...$this->header(), 'items' => [$this->groupLine($white, [[$w45, 2]], 16, 0)]])
            ->assertSessionHas('success');

        $order = PurchaseOrder::query()->latest('id')->firstOrFail();
        $line = $order->items()->with('pieces')->sole();
        $this->assertNull($line->product_id);
        $this->assertSame($white->id, (int) $line->category_finish_id);
        $this->assertSame('الوبين — أبيض', $line->description);
        $this->assertEquals(16, (float) $line->actual_weight);

        $this->get(route('accounting.purchase-orders.edit', $order->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.items.0.product_id', null)
                ->where('order.items.0.category_finish_id', $white->id)
                ->where('order.items.0.product_label', 'الوبين — أبيض')
                ->where('order.items.0.pieces.0.product_id', $w45->id));
    }

    public function test_confirm_then_quotation_then_cancel(): void
    {
        $order = $this->newOrder();

        $this->post(route('accounting.purchase-orders.confirm', $order->id), [...$this->header(), 'notes' => 'محفوظة قبل التأكيد', 'items' => $this->savedItems($order)])
            ->assertSessionHas('success');
        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::CONFIRMED, $order->status);
        $this->assertSame('محفوظة قبل التأكيد', $order->notes);

        $this->post(route('accounting.purchase-orders.quotation', $order->id));
        $quotation = PurchaseQuotation::query()->where('purchase_order_id', $order->id)->firstOrFail();

        // عرض فعّال واحد: الاجراء يفتح الموجود.
        $this->post(route('accounting.purchase-orders.quotation', $order->id))->assertRedirect(route('accounting.purchase-quotations.edit', $quotation->id));
        $this->assertSame(1, PurchaseQuotation::query()->where('purchase_order_id', $order->id)->count());

        $this->post(route('accounting.purchase-orders.cancel', $order->id))->assertSessionHas('success');
        $this->assertSame(PurchaseOrderStatus::CANCELLED, $order->refresh()->status);
    }

    public function test_an_order_is_invoiced_and_a_draft_is_deleted(): void
    {
        $order = $this->newOrder();

        $this->post(route('accounting.purchase-orders.invoice', $order->id));
        $invoice = PurchaseInvoice::query()->where('purchase_order_id', $order->id)->firstOrFail();
        $this->assertSame(PurchaseOrderStatus::INVOICED, $order->refresh()->status);

        $this->get(route('accounting.purchase-orders.edit', $order->id))
            ->assertInertia(fn (Assert $page) => $page->where('record.can_invoice', false)->where('record.editable', false));

        // حذف فاتورة المسودة يعيد الطلبية قابلة للفوترة.
        $this->delete(route('accounting.purchase-invoices.destroy', $invoice->id))->assertRedirect(route('accounting.purchase-invoices.index'));
        $this->assertNotSame(PurchaseOrderStatus::INVOICED, $order->refresh()->status);

        $draft = $this->newOrder();
        $this->delete(route('accounting.purchase-orders.destroy', $draft->id))->assertRedirect(route('accounting.purchase-orders.index'));
        $this->assertSoftDeleted($draft);
    }

    public function test_a_direct_shipment_is_prefilled_from_its_sales_order_and_marked_delivered(): void
    {
        $customer = User::create(['name' => 'زبون الشحن', 'phone' => '0599111111', 'password' => bcrypt('secret-password')]);
        $customer->assignRole(Role::firstOrCreate(['name' => 'Customer', 'guard_name' => 'web']));
        $salesOrder = SalesOrder::create([
            'client_id' => $customer->id,
            'reference_no' => 'SORD-TEST-1',
            'status' => SalesOrderStatus::CONFIRMED->value(),
            'order_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'exchange_rate' => 1,
            'direct_supplier_id' => $this->supplier()->id,
        ]);
        $salesOrder->items()->create(['product_id' => $this->demoProduct('DEMO-HINGE')->id, 'quantity' => 5, 'unit_price' => 7, 'line_total' => 35]);

        $this->get(route('accounting.purchase-orders.add', ['sales_order_id' => $salesOrder->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.delivery_type', PurchaseOrderDeliveryType::DIRECT->value)
                ->where('order.direct_customer_id', $customer->id)
                ->where('order.direct_sales_order_id', $salesOrder->id)
                ->where('customer.label', $customer->name)
                ->has('order.items', 1));

        $this->getJson(route('accounting.purchase-orders.customers', ['q' => 'زبون الشحن']))->assertJsonPath('0.value', $customer->id);
        $this->getJson(route('accounting.purchase-orders.sales-orders', ['customer_id' => $customer->id]))->assertJsonPath('0.value', $salesOrder->id);

        $this->post(route('accounting.purchase-orders.store'), [
            ...$this->header(),
            'delivery_type' => PurchaseOrderDeliveryType::DIRECT->value,
            'direct_customer_id' => $customer->id,
            'direct_sales_order_id' => $salesOrder->id,
            'items' => [['product_id' => $this->demoProduct('DEMO-HINGE')->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();
        $order = PurchaseOrder::query()->latest('id')->firstOrFail();

        $this->get(route('accounting.purchase-orders.edit', $order->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.direct.customer', $customer->name)
                ->where('record.can_mark_received', true)
                ->where('record.can_receive_goods', false));

        $this->post(route('accounting.purchase-orders.receive', $order->id))->assertSessionHas('success');
        $this->assertSame(PurchaseOrderStatus::RECEIVED, $order->refresh()->status);

        // بلا زبون لا شحن مباشر.
        $this->post(route('accounting.purchase-orders.store'), [...$this->header(), 'delivery_type' => PurchaseOrderDeliveryType::DIRECT->value, 'items' => []])
            ->assertSessionHasErrors('direct_customer_id');
    }

    public function test_a_received_order_shows_its_receipts_and_purchase_orders_suggest_no_accessories(): void
    {
        $order = $this->demoOrder('Partial receipt');

        $this->get(route('accounting.purchase-orders.edit', $order->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.receiving.receipts.0.reference_no', $order->goodsReceipts()->value('reference_no'))
                ->where('record.can_receive_goods', true)
                ->where('record.delivery_locked', true));

        $hinge = $this->demoProduct('DEMO-HINGE');
        ProductAccessory::create(['product_id' => $hinge->id, 'accessory_product_id' => $this->demoProduct('DEMO-LOCK')->id, 'quantity' => 2]);

        $payload = $this->getJson(route('accounting.items-grid.item', ['document' => 'purchase-order', 'product_id' => $hinge->id]))->assertOk()->json();
        $this->assertEmpty($payload['accessories'] ?? []);
    }

    public function test_info_and_print_open_and_every_action_needs_its_permission(): void
    {
        $order = $this->newOrder();

        $this->getJson(route('accounting.purchase-orders.info', $order->id))->assertOk()->assertJsonStructure(['activities']);
        $this->get(route('accounting.purchase-orders.pdf', $order->id))->assertOk();

        $this->signInWith(['Purchase Order View List']);

        $this->get(route('accounting.purchase-orders.index'))->assertOk();
        $this->get(route('accounting.purchase-orders.edit', $order->id))->assertOk();
        $this->get(route('accounting.purchase-orders.add'))->assertForbidden();
        $this->post(route('accounting.purchase-orders.store'), $this->header())->assertForbidden();
        $this->put(route('accounting.purchase-orders.update', $order->id), $this->header())->assertForbidden();
        $this->post(route('accounting.purchase-orders.confirm', $order->id))->assertForbidden();
        $this->post(route('accounting.purchase-orders.receive', $order->id))->assertForbidden();
        $this->post(route('accounting.purchase-orders.quotation', $order->id))->assertForbidden();
        $this->post(route('accounting.purchase-orders.invoice', $order->id))->assertForbidden();
        $this->post(route('accounting.purchase-orders.cancel', $order->id))->assertForbidden();
        $this->delete(route('accounting.purchase-orders.destroy', $order->id))->assertForbidden();
        $this->get(route('accounting.purchase-orders.pdf', $order->id))->assertForbidden();
        // Info is basic: whoever sees the list sees the row's history (View List).
        $this->getJson(route('accounting.purchase-orders.info', $order->id))->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function header(): array
    {
        return [
            'client_id' => $this->supplier()->id,
            'warehouse_id' => $this->warehouse()->id,
            'order_date' => now()->toDateString(),
            'delivery_type' => PurchaseOrderDeliveryType::WAREHOUSE->value,
            'notes' => 'جديدة',
        ];
    }

    private function newOrder(): PurchaseOrder
    {
        $this->post(route('accounting.purchase-orders.store'), [...$this->header(), 'items' => [['product_id' => $this->demoProduct('DEMO-LOCK')->id, 'quantity' => 3, 'unit_price' => 7]]])
            ->assertSessionHasNoErrors();

        return PurchaseOrder::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function savedItems(PurchaseOrder $order): array
    {
        return $order->items()->get()->map(fn ($item) => ['id' => $item->id, 'product_id' => $item->product_id, 'quantity' => (float) $item->quantity, 'unit_price' => (float) $item->unit_price])->all();
    }
}
