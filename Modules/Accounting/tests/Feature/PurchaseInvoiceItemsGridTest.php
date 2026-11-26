<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\tests\Feature\Concerns\PurchaseDocumentFixtures;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * فاتورة الشراء في لوحة Vue بنفس جدول اصناف الطلبية: الصنف الرئيسي واصنافه الفرعية.
 */
class PurchaseInvoiceItemsGridTest extends TestCase
{
    use PurchaseDocumentFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPurchaseDocuments();
    }

    public function test_an_invoice_from_an_order_shows_the_sub_items_of_its_main_item(): void
    {
        $invoice = $this->fullCycleInvoice();

        $this->get(route('accounting.purchase-invoices.edit', $invoice->id))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $main = collect($page->toArray()['props']['invoice']['items'])->firstWhere('pricing_type', 'weight');

                $this->assertCount(2, $main['pieces']);
                $this->assertSame('115.2000', number_format((float) $main['total_weight'], 4, '.', ''));
                $this->assertStringContainsString('ثابت 3 مجاري 1025', $main['pieces'][0]['product_label']);
                $this->assertNotNull($main['purchase_order_item_id']);
                $this->assertNotNull($main['pieces'][0]['purchase_order_item_piece_id']);

                // فاتورة طلبية: الاصناف كما في الطلبية؛ الاسعار والخصم يُدخلان قبل الترحيل.
                $page->component('Accounting/PurchaseInvoice/Edit')->where('record.items_editable', false);
            });
    }

    public function test_a_manual_invoice_saves_a_main_item_with_its_sub_items(): void
    {
        $invoice = $this->emptyInvoice();

        $this->put(route('accounting.purchase-invoices.update', $invoice->id), $this->payload([$this->weightLine()]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        // 3 × 6 × 0.9 = 16.2 كغم × 10.
        $line = $invoice->refresh()->items()->with('pieces')->firstOrFail();
        $this->assertSame(16.2, (float) $line->quantity);
        $this->assertSame(162.0, (float) $line->line_total);
        $this->assertCount(1, $line->pieces);

        // الحفظ التالي بنفس المعرفات يبقي السطر وقطعه.
        $this->get(route('accounting.purchase-invoices.edit', $invoice->id))->assertInertia(function (Assert $page) use ($line) {
            $row = $page->toArray()['props']['invoice']['items'][0];
            $this->assertSame($line->id, $row['id']);
            $this->assertSame($line->pieces->first()->id, $row['pieces'][0]['id']);
        });
    }

    public function test_a_manual_price_group_line_without_pieces_is_not_saved(): void
    {
        $invoice = $this->emptyInvoice();

        $this->put(route('accounting.purchase-invoices.update', $invoice->id), $this->payload([[
            'product_id' => null,
            'category_finish_id' => Product::query()->where('sku', 'DEMO-1025-01')->value('category_finish_id'),
            'pricing_type' => 'weight',
            'quantity' => 30,
            'unit_price' => 10,
            'pieces' => [],
        ]]))->assertSessionHas('error');

        $this->assertSame(0, PurchaseInvoice::query()->findOrFail($invoice->id)->items()->count());
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function payload(array $items): array
    {
        return [
            'client_id' => $this->supplier()->id,
            'warehouse_id' => $this->warehouse()->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => $items,
        ];
    }

    private function emptyInvoice(): PurchaseInvoice
    {
        return app(PurchaseInvoiceService::class)->create([
            'client_id' => $this->supplier()->id,
            'warehouse_id' => $this->warehouse()->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => [],
        ]);
    }
}
