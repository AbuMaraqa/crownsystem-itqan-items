<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\PurchaseQuotationStatus;
use Modules\Accounting\Services\PurchaseOrderService;
use Modules\Accounting\tests\Feature\Concerns\PurchaseDocumentFixtures;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * شاشات عرض سعر الشراء في لوحة Vue كما يستعملها الموظف: من طلبية الشراء الى العرض، تسعيره
 * واعتماده، ثم انشاء فاتورة الشراء منه؛ والالغاء والحذف والقائمة والطباعة والصلاحيات.
 */
class PurchaseQuotationPagesTest extends TestCase
{
    use PurchaseDocumentFixtures;
    use RefreshDatabase;

    private User $supplierUser;

    private Warehouse $warehouseRecord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPurchaseDocuments(demo: false);

        $this->supplierUser = User::factory()->create(['name' => 'مورد الالمنيوم']);
        $this->warehouseRecord = Warehouse::create(['code' => 'WH1', 'is_active' => true]);
        $this->warehouseRecord->translateOrNew('ar')->name = 'المخزن الرئيسي';
        $this->warehouseRecord->save();
    }

    public function test_quotation_is_created_from_the_order_priced_approved_and_invoiced(): void
    {
        $order = $this->purchaseOrder(6);

        $this->get(route('accounting.purchase-orders.edit', $order->id))
            ->assertInertia(fn (Assert $page) => $page->component('Accounting/PurchaseOrder/Edit')->where('record.can_create_quotation', true));

        $this->post(route('accounting.purchase-orders.quotation', $order->id))->assertRedirect();

        $quotation = PurchaseQuotation::query()->firstOrFail();
        $this->assertSame($order->id, $quotation->purchase_order_id);

        $this->get(route('accounting.purchase-quotations.edit', $quotation->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/PurchaseQuotation/Edit')
                ->where('order.reference_no', $order->reference_no)
                ->has('quotation.items', 1)
                ->where('record.editable', true));

        $line = $order->items()->firstOrFail();
        $this->post(route('accounting.purchase-quotations.approve', $quotation->id), $this->payload([
            'supplier_reference' => 'SUP-77',
            'items' => [['purchase_order_item_id' => $line->id, 'unit_price' => 12.5, 'discount_rate' => 0]],
        ]))->assertSessionHas('success');

        $quotation->refresh();
        $this->assertSame(PurchaseQuotationStatus::APPROVED, $quotation->status);
        $this->assertSame('SUP-77', $quotation->supplier_reference);
        $this->assertSame(75.0, (float) $quotation->total_amount);

        $this->get(route('accounting.purchase-quotations.edit', $quotation->id))
            ->assertInertia(fn (Assert $page) => $page->where('record.can_invoice', true)->where('record.editable', false));

        $this->post(route('accounting.purchase-quotations.invoice', $quotation->id))
            ->assertRedirect(route('accounting.purchase-invoices.edit', PurchaseInvoice::query()->value('id')));

        $invoice = PurchaseInvoice::query()->firstOrFail();
        $this->assertSame($quotation->id, $invoice->purchase_quotation_id);
        $this->assertSame(75.0, (float) $invoice->total_amount);
        $this->assertSame(PurchaseQuotationStatus::INVOICED, $quotation->refresh()->status);

        $this->get(route('accounting.purchase-orders.edit', $order->id))
            ->assertInertia(fn (Assert $page) => $page->where('record.quotation.reference_no', $quotation->reference_no));

        $this->get(route('accounting.purchase-quotations.edit', $quotation->id))
            ->assertInertia(fn (Assert $page) => $page->where('record.invoice.reference_no', $invoice->reference_no)->where('record.can_cancel', false));
    }

    public function test_add_page_picks_the_order_and_saves_the_prices(): void
    {
        $order = $this->purchaseOrder(4);

        $this->getJson(route('accounting.purchase-quotations.orders', ['q' => $order->reference_no]))
            ->assertOk()
            ->assertJsonPath('0.value', $order->id);

        $this->get(route('accounting.purchase-quotations.add', ['purchase_order' => $order->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/PurchaseQuotation/Add')
                ->where('quotation.purchase_order_id', $order->id)
                ->has('quotation.items', 1));

        $line = $order->items()->firstOrFail();
        $this->post(route('accounting.purchase-quotations.store'), $this->payload([
            'purchase_order_id' => $order->id,
            'items' => [['purchase_order_item_id' => $line->id, 'unit_price' => 10, 'discount_rate' => 5]],
        ]))->assertRedirect(route('accounting.purchase-quotations.edit', PurchaseQuotation::query()->value('id')));

        $quotation = PurchaseQuotation::query()->firstOrFail();
        $this->assertSame(PurchaseQuotationStatus::DRAFT, $quotation->status);
        $this->assertSame(38.0, (float) $quotation->total_amount);

        // للطلبية عرض فعّال واحد: صفحة الاضافة تفتحه بدل انشاء ثانٍ، ولا تعرضها قائمة الطلبيات.
        $this->get(route('accounting.purchase-quotations.add', ['purchase_order' => $order->id]))
            ->assertRedirect(route('accounting.purchase-quotations.edit', $quotation->id));
        $this->getJson(route('accounting.purchase-quotations.orders'))->assertJsonCount(0);
    }

    public function test_draft_is_updated_and_an_unpriced_line_blocks_approval_but_keeps_the_saved_draft(): void
    {
        $order = $this->purchaseOrder(3);
        $quotation = $this->createdQuotation($order);
        $line = $order->items()->firstOrFail();

        $this->put(route('accounting.purchase-quotations.update', $quotation->id), $this->payload([
            'notes' => 'ملاحظة',
            'items' => [['purchase_order_item_id' => $line->id, 'unit_price' => 0]],
        ]))->assertSessionHas('success');
        $this->assertSame('ملاحظة', $quotation->refresh()->notes);

        $this->post(route('accounting.purchase-quotations.approve', $quotation->id), $this->payload([
            'valid_until' => now()->addDays(5)->toDateString(),
            'items' => [['purchase_order_item_id' => $line->id, 'unit_price' => 0]],
        ]))->assertSessionHas('error');

        $quotation->refresh();
        $this->assertSame(PurchaseQuotationStatus::DRAFT, $quotation->status);
        $this->assertSame(now()->addDays(5)->toDateString(), $quotation->valid_until->toDateString());
    }

    public function test_quotation_is_cancelled_or_deleted(): void
    {
        $order = $this->purchaseOrder(2);
        $quotation = $this->createdQuotation($order);

        $this->post(route('accounting.purchase-quotations.cancel', $quotation->id))->assertSessionHas('success');
        $this->assertSame(PurchaseQuotationStatus::CANCELLED, $quotation->refresh()->status);

        // الالغاء يحرر الطلبية لعرض جديد يُحذف ما دام مسودة.
        $second = $this->createdQuotation($order);
        $this->delete(route('accounting.purchase-quotations.destroy', $second->id))->assertRedirect(route('accounting.purchase-quotations.index'));
        $this->assertSoftDeleted($second);
    }

    public function test_every_action_needs_its_permission(): void
    {
        $order = $this->purchaseOrder(2);
        $quotation = $this->createdQuotation($order);

        $this->signInWith(['Purchase Quotation View List']);

        $this->get(route('accounting.purchase-quotations.index'))->assertOk();
        $this->get(route('accounting.purchase-quotations.edit', $quotation->id))->assertOk();
        $this->get(route('accounting.purchase-quotations.add'))->assertForbidden();
        $this->post(route('accounting.purchase-quotations.store'), $this->payload(['purchase_order_id' => $order->id]))->assertForbidden();
        $this->put(route('accounting.purchase-quotations.update', $quotation->id), $this->payload())->assertForbidden();
        $this->post(route('accounting.purchase-quotations.approve', $quotation->id))->assertForbidden();
        $this->post(route('accounting.purchase-quotations.cancel', $quotation->id))->assertForbidden();
        $this->post(route('accounting.purchase-quotations.invoice', $quotation->id))->assertForbidden();
        $this->delete(route('accounting.purchase-quotations.destroy', $quotation->id))->assertForbidden();

        $this->signInWith([]);
        $this->get(route('accounting.purchase-quotations.index'))->assertForbidden();
    }

    /**
     * يولّد PDF: آخر اختبار في الصنف (بعد اول PDF يصبح الاسم المستعار PDF صنفاً، فيُقرأ فعل المسار 'pdf' اسم صنف).
     */
    public function test_index_info_and_print_open(): void
    {
        $order = $this->purchaseOrder(2);
        $quotation = $this->createdQuotation($order);

        $this->get(route('accounting.purchase-quotations.index', ['filter' => ['purchase_order' => $order->reference_no, 'status' => PurchaseQuotationStatus::DRAFT->value]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/PurchaseQuotation/Index')
                ->has('table.data', 1)
                ->where('table.data.0.reference_no', $quotation->reference_no)
                ->where('table.data.0.purchase_order', $order->reference_no));

        $this->getJson(route('accounting.purchase-quotations.info', $quotation->id))->assertOk()->assertJsonStructure(['activities']);
        $this->get(route('accounting.purchase-quotations.pdf', $quotation->id))->assertOk();
        $this->get(route('accounting.purchase-orders.pdf', $order->id))->assertOk();
    }

    private function createdQuotation(PurchaseOrder $order): PurchaseQuotation
    {
        $this->post(route('accounting.purchase-quotations.store'), $this->payload(['purchase_order_id' => $order->id]));

        return PurchaseQuotation::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data = []): array
    {
        return array_replace([
            'quotation_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'quotation_discount' => 0,
            'items' => [],
        ], $data);
    }

    private function purchaseOrder(float $quantity): PurchaseOrder
    {
        $product = new Product([
            'slug' => 'pqp-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => 'PQP-'.Str::random(6),
            'base_price' => 10,
            'cost_price' => 8,
            'sale_price' => 20,
            'pricing_type' => 'simple',
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = 'مقطع';
        $product->save();

        $order = app(PurchaseOrderService::class)->create([
            'client_id' => $this->supplierUser->id,
            'warehouse_id' => $this->warehouseRecord->id,
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ]);

        $this->assertSame(PurchaseOrderStatus::DRAFT, $order->status);

        return $order;
    }
}
