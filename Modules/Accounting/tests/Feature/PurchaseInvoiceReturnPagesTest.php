<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseReturn;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\PurchaseQuotationStatus;
use Modules\Accounting\Enums\PurchaseReturnStatus;
use Modules\Accounting\tests\Feature\Concerns\PurchaseDocumentFixtures;
use Tests\TestCase;

/**
 * فواتير ومرتجعات الشراء في لوحة Vue: الفاتورة اليدوية ومن طلبية (باسعار عرض السعر المعتمد)،
 * الترحيل (قيد ودخول البضاعة المخزون) والالغاء (قيد عكسي وعكس المخزون)، ثم المرتجع من فاتورة
 * مرحّلة بقطع اصنافه الفرعية: الترحيل يخرج البضاعة ويقيد ويخصص على الفاتورة، والالغاء يعكسه.
 */
class PurchaseInvoiceReturnPagesTest extends TestCase
{
    use PurchaseDocumentFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPurchaseDocuments();
    }

    public function test_invoice_index_lists_and_filters(): void
    {
        $invoice = $this->fullCycleInvoice();

        $this->get(route('accounting.purchase-invoices.index', ['filter' => [
            'reference_no' => $invoice->reference_no,
            'status' => PurchaseInvoiceStatus::POSTED->value,
            'document_state' => 'with_journal',
            'item_line_total_from' => 1,
        ]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/PurchaseInvoice/Index')
                ->has('table.data', 1)
                ->where('table.data.0.reference_no', $invoice->reference_no)
                ->where('table.data.0.editable', false));

        $this->get(route('accounting.purchase-invoices.index', ['filter' => ['document_state' => 'without_journal', 'reference_no' => $invoice->reference_no]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 0));
    }

    public function test_a_manual_invoice_is_added_posted_into_stock_and_cancelled(): void
    {
        $sub = $this->demoProduct('DEMO-1025-02');
        $before = $this->onHand($sub);

        $this->get(route('accounting.purchase-invoices.add'))
            ->assertInertia(fn (Assert $page) => $page->component('Accounting/PurchaseInvoice/Add')->where('invoice.currency_id', $this->currency->id));

        $this->post(route('accounting.purchase-invoices.store'), $this->invoicePayload([
            $this->weightLine(),
            ['product_id' => $this->demoProduct('DEMO-SCREW')->id, 'quantity' => 100, 'unit_price' => 0.05],
        ]))->assertSessionHasNoErrors()->assertSessionHas('success');

        $invoice = PurchaseInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame(162.0, (float) $invoice->items()->whereNotNull('total_weight')->where('total_weight', '>', 0)->value('line_total'));
        $this->assertSame(167.0, (float) $invoice->total_amount);

        $this->get(route('accounting.purchase-invoices.edit', $invoice->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.editable', true)
                ->where('record.items_editable', true)
                ->where('allocationPanel', null));

        // الترحيل يحفظ ما أُدخل اولاً.
        $this->post(route('accounting.purchase-invoices.post', $invoice->id), [...$this->invoicePayload($this->savedLines($invoice)), 'due_date' => now()->addMonth()->toDateString()])
            ->assertSessionHas('success');

        $invoice->refresh();
        $this->assertSame(PurchaseInvoiceStatus::POSTED, $invoice->status);
        $this->assertSame(now()->addMonth()->toDateString(), $invoice->due_date->toDateString());

        $entry = JournalEntry::query()->with('lines')->findOrFail($invoice->journal_entry_id);
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);
        $this->assertEqualsWithDelta((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'), 0.0001);
        $this->assertEqualsWithDelta(167.0, (float) $entry->lines->sum('credit'), 0.0001);

        // القطع الثلاث تدخل المخزن بصنفها الفرعي.
        $this->assertSame($before + 3, $this->onHand($sub));
        $this->assertTrue(StockMovement::query()->where('sourceable_type', $invoice::class)->where('sourceable_id', $invoice->id)->exists());

        $this->get(route('accounting.purchase-invoices.edit', $invoice->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.editable', false)
                ->where('record.can_cancel', true)
                ->where('allocationPanel.is_invoice', true)
                ->where('allocationPanel.outstanding', '167'));

        $this->post(route('accounting.purchase-invoices.cancel', $invoice->id), ['reason' => 'خطأ في الادخال'])->assertSessionHas('success');

        $invoice->refresh();
        $this->assertSame(PurchaseInvoiceStatus::CANCELLED, $invoice->status);
        $this->assertSame('خطأ في الادخال', $invoice->cancellation_reason);
        $this->assertSame(JournalEntryStatus::VOIDED, $entry->refresh()->status);
        $this->assertSame($before, $this->onHand($sub));
    }

    public function test_an_unpriced_line_blocks_posting_but_the_draft_is_saved(): void
    {
        $this->post(route('accounting.purchase-invoices.store'), $this->invoicePayload([
            ['product_id' => $this->demoProduct('DEMO-LOCK')->id, 'quantity' => 2, 'unit_price' => 5],
        ]));
        $invoice = PurchaseInvoice::query()->latest('id')->firstOrFail();
        $lines = $this->savedLines($invoice);
        $lines[] = ['product_id' => $this->demoProduct('DEMO-SCREW')->id, 'quantity' => 10, 'unit_price' => 0];

        $this->post(route('accounting.purchase-invoices.post', $invoice->id), $this->invoicePayload($lines))->assertSessionHas('error');

        $this->assertSame(PurchaseInvoiceStatus::DRAFT, $invoice->refresh()->status);
        $this->assertSame(2, $invoice->items()->count());
    }

    public function test_an_invoice_is_made_from_an_order_with_its_approved_quotation(): void
    {
        $order = $this->demoOrder('Partial receipt');
        $quotation = $order->activePurchaseQuotation();

        $this->getJson(route('accounting.purchase-invoices.orders', ['client_id' => $this->supplier()->id]))
            ->assertOk()
            ->assertJsonFragment(['value' => $order->id]);

        $draft = $this->getJson(route('accounting.purchase-invoices.order-draft', ['purchase_order_id' => $order->id, 'invoice_date' => now()->toDateString()]))
            ->assertOk()
            ->assertJsonPath('purchase_order_id', $order->id)
            ->json();

        // اسعار عرض السعر المعتمد: 7 للقفل و15 للكغم.
        $this->assertEqualsCanonicalizing(['7.0000', '15.0000'], collect($draft['items'])->pluck('unit_price')->all());
        $this->assertNotEmpty(collect($draft['items'])->firstWhere('pieces', '!=', [])['pieces'][0]['purchase_order_item_piece_id']);

        $this->post(route('accounting.purchase-invoices.store'), [...$draft, 'invoice_date' => now()->toDateString(), 'notes' => null])
            ->assertSessionHasNoErrors();

        $invoice = PurchaseInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame($order->id, $invoice->purchase_order_id);
        $this->assertSame($quotation->id, $invoice->purchase_quotation_id);
        $this->assertSame(PurchaseOrderStatus::INVOICED, $order->refresh()->status);
        $this->assertSame(PurchaseQuotationStatus::INVOICED, $quotation->refresh()->status);
        $this->assertTrue($invoice->items()->whereNotNull('purchase_order_item_id')->exists());

        // عرض سعر مسودة يمنع فوترة طلبيته.
        $this->getJson(route('accounting.purchase-invoices.order-draft', ['purchase_order_id' => $this->demoOrder('Draft quotation')->id]))
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_a_return_is_added_from_a_posted_invoice_edited_posted_and_cancelled(): void
    {
        $invoice = $this->fullCycleInvoice();
        $sub = $this->demoProduct('DEMO-1025-01');
        $before = $this->onHand($sub);
        $outstanding = (float) $invoice->outstanding_amount;

        $this->getJson(route('accounting.purchase-returns.invoices', ['client_id' => $this->supplier()->id]))
            ->assertOk()
            ->assertJsonFragment(['value' => $invoice->id]);
        $this->getJson(route('accounting.purchase-returns.invoice-draft', ['purchase_invoice_id' => $invoice->id, 'client_id' => $this->supplier()->id]))
            ->assertOk()
            ->assertJsonPath('currency_id', $invoice->currency_id);

        $this->post(route('accounting.purchase-returns.store'), [
            'client_id' => $this->supplier()->id,
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $return = PurchaseReturn::query()->latest('id')->firstOrFail();
        $this->assertSame(PurchaseReturnStatus::DRAFT, $return->status);

        $items = [];
        $this->get(route('accounting.purchase-returns.edit', $return->id))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$items) {
                $page->component('Accounting/PurchaseReturn/Edit')->where('record.editable', true);
                $items = $page->toArray()['props']['purchaseReturn']['items'];
            });

        $weight = collect($items)->firstWhere('pricing_type', 'weight');
        $this->assertCount(2, $weight['pieces']);
        $this->assertSame(10.0, (float) $weight['pieces'][0]['max_piece_count']);

        // يُرجع 4 قطع من الصنف الفرعي الاول فقط.
        $items = collect($items)->map(function (array $item) {
            $item['quantity'] = 0;
            $item['pieces'] = collect($item['pieces'])->values()->map(fn (array $piece, int $index) => array_merge($piece, ['piece_count' => $index === 0 ? 4 : 0]))->all();

            return $item;
        })->all();

        $this->put(route('accounting.purchase-returns.update', $return->id), ['return_date' => now()->toDateString(), 'items' => $items])
            ->assertSessionHas('success');

        // 4 قطع × 6 م × 1.2 = 28.8 كغم.
        $line = $return->refresh()->items()->with('pieces')->firstOrFail();
        $this->assertSame(28.8, (float) $line->quantity);
        $this->assertSame(4.0, (float) $line->pieces->sum('piece_count'));

        $this->post(route('accounting.purchase-returns.post', $return->id))->assertSessionHas('success');

        $return->refresh();
        $this->assertSame(PurchaseReturnStatus::POSTED, $return->status);
        $this->assertSame($before - 4, $this->onHand($sub));
        $this->assertSame(JournalEntryStatus::POSTED, $return->journalEntry->status);
        // المرتجع يخصَّص على فاتورته.
        $this->assertEqualsWithDelta($outstanding - (float) $return->total_amount, (float) $invoice->refresh()->outstanding_amount, 0.0001);

        $this->get(route('accounting.purchase-returns.edit', $return->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.can_cancel', true)
                ->where('allocationPanel.is_invoice', false)
                ->has('allocationPanel.allocations', 1));

        $this->post(route('accounting.purchase-returns.cancel', $return->id), ['reason' => 'ارجاع خاطئ'])->assertSessionHas('success');

        $return->refresh();
        $this->assertSame(PurchaseReturnStatus::CANCELLED, $return->status);
        $this->assertSame('ارجاع خاطئ', $return->cancellation_reason);
        $this->assertSame($before, $this->onHand($sub));
        $this->assertEqualsWithDelta($outstanding, (float) $invoice->refresh()->outstanding_amount, 0.0001);
    }

    public function test_a_return_beyond_the_returnable_pieces_is_refused_and_a_draft_is_deleted(): void
    {
        $invoice = $this->fullCycleInvoice();

        $this->post(route('accounting.purchase-returns.store'), ['client_id' => $this->supplier()->id, 'purchase_invoice_id' => $invoice->id, 'return_date' => now()->toDateString()]);
        $return = PurchaseReturn::query()->latest('id')->firstOrFail();
        $line = $invoice->items()->with('pieces')->get()->first(fn ($item) => $item->pieces->isNotEmpty());

        $this->put(route('accounting.purchase-returns.update', $return->id), [
            'return_date' => now()->toDateString(),
            'items' => [['purchase_invoice_item_id' => $line->id, 'pieces' => [['purchase_invoice_item_piece_id' => $line->pieces->first()->id, 'piece_count' => 11]]]],
        ])->assertSessionHas('error');

        // فاتورة لمورد آخر لا تُقبل.
        $this->post(route('accounting.purchase-returns.store'), ['client_id' => $this->signInWith(self::PURCHASE_PERMISSIONS)->id, 'purchase_invoice_id' => $invoice->id, 'return_date' => now()->toDateString()])
            ->assertSessionHasErrors('purchase_invoice_id');

        $this->delete(route('accounting.purchase-returns.destroy', $return->id))->assertRedirect(route('accounting.purchase-returns.index'));
        $this->assertSoftDeleted($return);

        $this->get(route('accounting.purchase-returns.index', ['filter' => ['trashed' => 'only']]))
            ->assertInertia(fn (Assert $page) => $page->component('Accounting/PurchaseReturn/Index')->where('table.data.0.deleted', true));
    }

    public function test_prints_and_permissions(): void
    {
        $invoice = $this->fullCycleInvoice();
        $this->post(route('accounting.purchase-returns.store'), ['client_id' => $this->supplier()->id, 'purchase_invoice_id' => $invoice->id, 'return_date' => now()->toDateString()]);
        $return = PurchaseReturn::query()->latest('id')->firstOrFail();

        $this->get(route('accounting.purchase-invoices.pdf', $invoice->id))->assertOk();
        $this->get(route('accounting.purchase-returns.pdf', $return->id))->assertOk();
        $this->getJson(route('accounting.purchase-invoices.info', $invoice->id))->assertOk();
        $this->getJson(route('accounting.purchase-returns.info', $return->id))->assertOk();

        $this->signInWith(['Purchase Invoice View List', 'Purchase Return View List']);

        $this->get(route('accounting.purchase-invoices.index'))->assertOk();
        $this->get(route('accounting.purchase-invoices.edit', $invoice->id))->assertOk()->assertInertia(fn (Assert $page) => $page->where('record.editable', false));
        $this->get(route('accounting.purchase-invoices.add'))->assertForbidden();
        $this->post(route('accounting.purchase-invoices.store'))->assertForbidden();
        $this->put(route('accounting.purchase-invoices.update', $invoice->id))->assertForbidden();
        $this->post(route('accounting.purchase-invoices.post', $invoice->id))->assertForbidden();
        $this->post(route('accounting.purchase-invoices.cancel', $invoice->id))->assertForbidden();
        $this->delete(route('accounting.purchase-invoices.destroy', $invoice->id))->assertForbidden();
        $this->get(route('accounting.purchase-invoices.pdf', $invoice->id))->assertForbidden();

        $this->get(route('accounting.purchase-returns.index'))->assertOk();
        $this->get(route('accounting.purchase-returns.edit', $return->id))->assertForbidden();
        $this->post(route('accounting.purchase-returns.store'))->assertForbidden();
        $this->post(route('accounting.purchase-returns.post', $return->id))->assertForbidden();
        $this->post(route('accounting.purchase-returns.cancel', $return->id))->assertForbidden();
        $this->delete(route('accounting.purchase-returns.destroy', $return->id))->assertForbidden();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function invoicePayload(array $items): array
    {
        return [
            'client_id' => $this->supplier()->id,
            'warehouse_id' => $this->warehouse()->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'invoice_discount' => 0,
            'items' => $items,
        ];
    }

    /**
     * سطور الفاتورة المحفوظة كما يعيدها الجدول (بمعرفاتها).
     *
     * @return array<int, array<string, mixed>>
     */
    private function savedLines(PurchaseInvoice $invoice): array
    {
        $rows = [];
        $this->get(route('accounting.purchase-invoices.edit', $invoice->id))->assertInertia(function (Assert $page) use (&$rows) {
            $rows = $page->toArray()['props']['invoice']['items'];
        });

        return $rows;
    }
}
