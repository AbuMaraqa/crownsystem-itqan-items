<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Services\GoodsReceiptService;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\tests\Feature\Concerns\SalesDocumentFixtures;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * الحذف الجماعي في قوائم المستندات (فاتورة البيع وسند الاستلام مثالاً): تُحذف المسودات المحددة بخدمة
 * الحذف المفرد نفسها، والمرحّل او المحذوف سابقاً يبقى برسالة سببه، وبلا صلاحية الحذف يُرفض الطلب.
 */
class DocumentBulkDeleteTest extends TestCase
{
    use RefreshDatabase;
    use SalesDocumentFixtures;

    private const RECEIPT_PERMISSIONS = ['Goods Receipt View List', 'Goods Receipt Delete'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSalesDocuments();

        foreach (self::RECEIPT_PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function draftInvoice(): SalesInvoice
    {
        return app(SalesInvoiceService::class)->saveForm(null, $this->invoiceData([$this->weightLine()]));
    }

    public function test_the_selected_draft_invoices_are_deleted(): void
    {
        $first = $this->draftInvoice();
        $second = $this->draftInvoice();

        $this->delete(route('accounting.sales-invoices.destroy-many'), ['ids' => [$first->id, $second->id]])
            ->assertSessionHas('success', __('The selected rows were deleted (:count).', ['count' => 2]));

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
    }

    public function test_a_posted_or_already_deleted_invoice_in_the_selection_stays(): void
    {
        $draft = $this->draftInvoice();
        $posted = $this->postedInvoice();

        $this->delete(route('accounting.sales-invoices.destroy-many'), ['ids' => [$draft->id, $posted->id]])
            ->assertSessionHas('warning', __(':deleted deleted, :skipped not deleted. :reason', [
                'deleted' => 1,
                'skipped' => 1,
                'reason' => __('Only draft sales invoices can be deleted.'),
            ]));

        $this->assertSoftDeleted($draft);
        $this->assertNotSoftDeleted($posted);

        // The «deleted» filter shows the deleted draft: selecting it again is refused, not deleted twice.
        $this->delete(route('accounting.sales-invoices.destroy-many'), ['ids' => [$posted->id, $draft->id]])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, __('Only draft sales invoices can be deleted.'))
                && str_contains($message, __('This document has already been deleted.')));
        $this->assertNotSoftDeleted($posted);
    }

    public function test_bulk_deleting_invoices_needs_the_delete_permission(): void
    {
        $draft = $this->draftInvoice();

        $this->signIn(['Sales Invoice View List']);
        $this->delete(route('accounting.sales-invoices.destroy-many'), ['ids' => [$draft->id]])->assertForbidden();

        $this->assertNotSoftDeleted($draft);
    }

    public function test_draft_receipts_are_deleted_and_a_posted_one_stays(): void
    {
        $this->signIn([...self::RECEIPT_PERMISSIONS]);
        $order = $this->purchaseOrder();
        $service = app(GoodsReceiptService::class);
        $first = $this->draftReceipt($order);
        $second = $this->draftReceipt($order);
        $posted = $service->post($this->draftReceipt($order));

        $this->delete(route('accounting.inventory.goods-receipts.destroy-many'), ['ids' => [$first->id, $second->id, $posted->id]])
            ->assertSessionHas('warning', __(':deleted deleted, :skipped not deleted. :reason', [
                'deleted' => 2,
                'skipped' => 1,
                'reason' => __('Only draft documents can be deleted.'),
            ]));

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
        $this->assertNotSoftDeleted($posted);
        $this->assertTrue($posted->fresh()->isPosted());

        $this->delete(route('accounting.inventory.goods-receipts.destroy-many'), ['ids' => [$posted->id]])
            ->assertSessionHas('error', __('Nothing was deleted. :reason', ['reason' => __('Only draft documents can be deleted.')]));

        $draft = $this->draftReceipt($order);
        $this->delete(route('accounting.inventory.goods-receipts.destroy-many'), ['ids' => [$draft->id]])
            ->assertSessionHas('success', __('The selected rows were deleted (:count).', ['count' => 1]));
    }

    public function test_bulk_deleting_receipts_needs_the_delete_permission(): void
    {
        $draft = $this->draftReceipt($this->purchaseOrder());

        $this->signIn(['Goods Receipt View List']);
        $this->delete(route('accounting.inventory.goods-receipts.destroy-many'), ['ids' => [$draft->id]])->assertForbidden();

        $this->assertNotSoftDeleted($draft);
    }

    private function draftReceipt(PurchaseOrder $order): GoodsReceipt
    {
        return app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $order->items->first()->id, 'quantity' => 1]],
        ]);
    }

    private function purchaseOrder(): PurchaseOrder
    {
        $product = new Product([
            'slug' => 'p-'.Str::random(8), 'sku' => 'S-'.Str::random(6), 'barcode' => 'B-'.Str::random(6),
            'base_price' => 10, 'cost_price' => 8, 'pricing_type' => 'simple',
            'min_limit_1' => 5, 'min_limit_2' => 2, 'max_limit' => 20,
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = 'مفصلة';
        $product->save();

        $order = PurchaseOrder::create([
            'reference_no' => 'PO-'.Str::random(6), 'client_id' => User::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id, 'order_date' => now()->toDateString(),
            'currency_id' => $this->currency->id, 'exchange_rate' => 1,
            'sub_total' => 250, 'total_amount' => 250,
            'status' => PurchaseOrderStatus::CONFIRMED->value(),
        ]);
        $order->items()->create(['product_id' => $product->id, 'description' => 'مفصلة', 'quantity' => 10, 'unit_price' => 25, 'line_total' => 250]);

        return $order->refresh();
    }
}
