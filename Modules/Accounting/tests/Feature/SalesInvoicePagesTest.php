<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\SalesOrderService;
use Modules\Accounting\tests\Feature\Concerns\PriceGroupFixtures;
use Modules\Accounting\tests\Feature\Concerns\SalesDocumentFixtures;
use Tests\TestCase;

/**
 * فواتير البيع في لوحة Vue: الفاتورة اليدوية بسطور جدول الاصناف، والفاتورة من طلبية (اصنافها
 * مقفلة، والوزن الفعلي والسعر يُدخلان)، والخدمات، والترحيل (قيد الايراد وخروج القطع من المخزن)،
 * والالغاء بسبب (قيد عكسي وعودة البضاعة)، ولوحة ما سُدِّد، وكل اجراء بصلاحيته.
 */
class SalesInvoicePagesTest extends TestCase
{
    use PriceGroupFixtures;
    use RefreshDatabase;
    use SalesDocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSalesDocuments();
    }

    public function test_a_manual_invoice_is_saved_with_its_lines_from_the_add_page(): void
    {
        $this->get(route('accounting.sales-invoices.add'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/SalesInvoice/Add')
                ->where('invoice.items', [])
                ->has('serviceOptions.cost_sources', 2));

        $this->post(route('accounting.sales-invoices.store'), $this->invoiceData([$this->weightLine(3, 10)]))
            ->assertRedirect()
            ->assertSessionHas('success');

        // 3 × 6 × 1.2 = 21.6 كغم × 10.
        $line = SalesInvoice::query()->latest('id')->firstOrFail()->items()->with('pieces')->sole();
        $this->assertSame(21.6, (float) $line->quantity);
        $this->assertSame(216.0, (float) $line->line_total);
        $this->assertCount(1, $line->pieces);
    }

    public function test_a_manual_main_item_without_pieces_is_refused(): void
    {
        $line = array_replace($this->weightLine(), ['pieces' => [], 'quantity' => 30]);

        $this->post(route('accounting.sales-invoices.store'), $this->invoiceData([$line]))->assertSessionHas('error');
        $this->assertSame(0, SalesInvoice::query()->count());
    }

    public function test_choosing_an_order_on_the_add_page_moves_its_lines_and_services(): void
    {
        $service = $this->serviceProduct();
        $order = $this->draftOrder([$this->weightLine(2, 20)], ['services' => [$this->serviceLine($service)]]);
        $invoiced = $this->draftOrder();
        app(SalesInvoiceService::class)->createFromOrder($invoiced);

        $this->getJson(route('accounting.sales-invoices.orders', ['client_id' => $this->customer->id]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.value', $order->id);

        $this->getJson(route('accounting.sales-invoices.order-defaults', ['sales_order_id' => $order->id]))
            ->assertOk()
            ->assertJsonPath('client.value', $this->customer->id)
            ->assertJsonPath('totals.total_amount', 388);

        $this->post(route('accounting.sales-invoices.store'), $this->invoiceData([], ['sales_order_id' => $order->id]))->assertSessionHas('success');

        $invoice = SalesInvoice::query()->where('sales_order_id', $order->id)->sole();
        $this->assertCount(2, $invoice->items);
        $this->assertSame(40.0, (float) $invoice->items()->where('line_type', 'service')->value('cost_total'));
        $this->assertSame(388.0, (float) $invoice->total_amount);
        $this->assertSame(SalesOrderStatus::INVOICED, $order->refresh()->status);
    }

    public function test_an_invoice_from_an_order_keeps_its_items_and_takes_the_actual_weight(): void
    {
        $order = $this->draftOrder([$this->weightLine(2, 20)]);
        $invoice = app(SalesInvoiceService::class)->createFromOrder($order);
        $line = $invoice->items()->with('pieces')->sole();

        $response = $this->get(route('accounting.sales-invoices.edit', $invoice->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/SalesInvoice/Edit')
                ->where('record.from_order', true)
                ->where('record.editable', true)
                ->where('record.sales_order.id', $order->id)
                ->where('invoice.items.0.id', $line->id)
                ->where('invoice.items.0.sales_order_item_id', $line->sales_order_item_id)
                ->where('invoice.items.0.pieces.0.sales_order_item_piece_id', $line->pieces->first()->sales_order_item_piece_id)
                ->where('allocationPanel', null));

        $form = $this->props($response)['invoice'];
        $form['items'][0]['actual_weight'] = 15;
        $form['items'][0]['unit_price'] = 22;

        $this->put(route('accounting.sales-invoices.update', $invoice->id), $form)->assertSessionHas('success');

        $saved = $invoice->items()->with('pieces')->sole();
        $this->assertSame($line->id, $saved->id);
        $this->assertSame($line->pieces->first()->id, $saved->pieces->first()->id);
        $this->assertSame($line->pieces->first()->sales_order_item_piece_id, $saved->pieces->first()->sales_order_item_piece_id);
        $this->assertSame(330.0, (float) $saved->line_total);
    }

    public function test_a_price_group_line_is_saved_from_the_page_and_carried_from_an_order(): void
    {
        ['white' => $white, 'whiteW45' => $whiteW45, 'meshW45' => $meshW45] = $this->alpineGroups();

        // يدوياً: سطر «الوبين — أبيض» يُحفظ ويُفتح كما هو، ولون آخر داخله مرفوض.
        $this->post(route('accounting.sales-invoices.store'), $this->invoiceData([$this->groupLine($white, [[$meshW45, 1]], 8)]))->assertSessionHas('error');
        $this->post(route('accounting.sales-invoices.store'), $this->invoiceData([$this->groupLine($white, [[$whiteW45, 2]], 16)]))->assertSessionHas('success');

        $manual = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->get(route('accounting.sales-invoices.edit', $manual->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoice.items.0.product_id', null)
                ->where('invoice.items.0.category_finish_id', $white->id)
                ->where('invoice.items.0.product_label', 'الوبين — أبيض')
                ->where('invoice.items.0.pieces.0.product_id', $whiteW45->id));
        $this->assertSame(320.0, (float) $manual->total_amount);

        // من طلبية: السطر ينتقل بمجموعته وقطعه، والوزن الفعلي في الفاتورة يحدد المبلغ.
        $order = $this->draftOrder([$this->groupLine($white, [[$whiteW45, 3]], 24)]);
        $invoice = app(SalesInvoiceService::class)->createFromOrder($order);
        $form = $this->props($this->get(route('accounting.sales-invoices.edit', $invoice->id)))['invoice'];
        $this->assertNull($form['items'][0]['product_id']);
        $this->assertSame($white->id, $form['items'][0]['category_finish_id']);

        $form['items'][0]['actual_weight'] = 25;
        $this->put(route('accounting.sales-invoices.update', $invoice->id), $form)->assertSessionHas('success');

        $saved = $invoice->items()->with('pieces')->sole();
        $this->assertNull($saved->product_id);
        $this->assertSame($white->id, (int) $saved->category_finish_id);
        $this->assertNotNull($saved->sales_order_item_id);
        $this->assertSame(500.0, (float) $saved->line_total);
    }

    public function test_the_invoice_page_lists_service_lines_in_the_services_section(): void
    {
        $order = $this->draftOrder([$this->weightLine()], ['services' => [$this->serviceLine($this->serviceProduct())]]);
        $invoice = app(SalesInvoiceService::class)->createFromOrder($order);
        $serviceLine = $invoice->items->firstWhere('line_type', 'service');

        $response = $this->get(route('accounting.sales-invoices.edit', $invoice->id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('invoice.items', 1)
                ->where('invoice.services.0.id', $serviceLine->id)
                ->where('invoice.services.0.cost_source', 'internal')
                ->where('invoice.services.0.sales_order_item_id', $serviceLine->sales_order_item_id));

        $this->put(route('accounting.sales-invoices.update', $invoice->id), $this->props($response)['invoice'])->assertSessionHas('success');

        $this->assertSame(40.0, (float) $invoice->items()->where('line_type', 'service')->value('cost_total'));
        $this->assertSame($serviceLine->id, (int) $invoice->items()->where('line_type', 'service')->value('id'));
    }

    public function test_posting_saves_first_issues_the_pieces_and_posts_the_journal_entry(): void
    {
        $sub = $this->product('DEMO-1025-01');
        $before = $this->onHand($sub);
        $invoice = app(SalesInvoiceService::class)->saveForm(null, $this->invoiceData([$this->weightLine(2, 20)]));

        $form = $this->props($this->get(route('accounting.sales-invoices.edit', $invoice->id)))['invoice'];
        $form['notes'] = 'مرحّلة';

        $this->post(route('accounting.sales-invoices.post', $invoice->id), $form)->assertSessionHas('success');

        $invoice->refresh();
        $this->assertSame(SalesInvoiceStatus::POSTED, $invoice->status);
        $this->assertSame('مرحّلة', $invoice->notes);
        $this->assertSame(JournalEntryStatus::POSTED, JournalEntry::query()->findOrFail($invoice->journal_entry_id)->status);
        $this->assertSame($before - 2, $this->onHand($sub));
        $this->assertTrue(StockMovement::query()->where('sourceable_type', $invoice->getMorphClass())->where('sourceable_id', $invoice->id)->exists());

        $this->get(route('accounting.sales-invoices.edit', $invoice->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.editable', false)
                ->where('record.can_post', false)
                ->where('record.can_cancel', true)
                ->where('allocationPanel.is_invoice', true)
                ->where('allocationPanel.outstanding', '288'));

        $this->post(route('accounting.sales-invoices.post', $invoice->id))->assertSessionHas('error');
    }

    public function test_a_posted_invoice_is_cancelled_with_its_reason(): void
    {
        $sub = $this->product('DEMO-1025-01');
        $invoice = $this->postedInvoice(2);
        $afterSale = $this->onHand($sub);

        $this->post(route('accounting.sales-invoices.cancel', $invoice->id), ['reason' => 'سعر خاطئ'])->assertSessionHas('success');

        $invoice->refresh();
        $this->assertSame(SalesInvoiceStatus::CANCELLED, $invoice->status);
        $this->assertSame('سعر خاطئ', $invoice->cancellation_reason);
        $this->assertSame(JournalEntryStatus::VOIDED, JournalEntry::query()->findOrFail($invoice->journal_entry_id)->status);
        $this->assertSame($afterSale + 2, $this->onHand($sub));

        $this->get(route('accounting.sales-invoices.edit', $invoice->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.cancelled', true)
                ->where('record.cancellation_reason', 'سعر خاطئ')
                ->where('record.can_cancel', false));
    }

    public function test_cancelling_needs_the_cancel_permission(): void
    {
        $invoice = $this->postedInvoice();
        $this->signIn(['Sales Invoice View List', 'Sales Invoice Update', 'Sales Invoice Post']);

        $this->post(route('accounting.sales-invoices.cancel', $invoice->id))->assertForbidden();
        $this->assertSame(SalesInvoiceStatus::POSTED, $invoice->refresh()->status);
    }

    public function test_a_validation_error_returns_field_errors(): void
    {
        $invoice = app(SalesInvoiceService::class)->saveForm(null, $this->invoiceData([$this->weightLine()]));

        $this->put(route('accounting.sales-invoices.update', $invoice->id), $this->invoiceData([$this->weightLine()], ['invoice_date' => null]))
            ->assertSessionHasErrors(['invoice_date']);
        $this->assertSame(SalesInvoiceStatus::DRAFT, $invoice->refresh()->status);
    }

    public function test_deleting_an_invoice_from_an_order_reopens_the_order(): void
    {
        $order = $this->draftOrder();
        app(SalesOrderService::class)->sendToPreparation($order);
        $invoice = app(SalesInvoiceService::class)->createFromOrder($order);

        $this->delete(route('accounting.sales-invoices.destroy', $invoice->id))->assertSessionHas('success');

        $this->assertSame(SalesOrderStatus::PREPARING, SalesOrder::query()->findOrFail($order->id)->status);
    }

    public function test_the_list_filters_and_the_read_only_viewer(): void
    {
        $posted = $this->postedInvoice();
        $draft = app(SalesInvoiceService::class)->saveForm(null, $this->invoiceData([$this->weightLine()]));

        $this->get(route('accounting.sales-invoices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Accounting/SalesInvoice/Index')->has('table.data', 2));
        $this->get(route('accounting.sales-invoices.index', ['filter' => ['journal_state' => 'without_journal']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.id', $draft->id));
        $this->get(route('accounting.sales-invoices.index', ['filter' => ['status' => SalesInvoiceStatus::POSTED->value()]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.editable', false));

        // الصفحة نفسها للقراءة لمن يرى القائمة فقط.
        $this->signIn(['Sales Invoice View List']);
        $this->get(route('accounting.sales-invoices.edit', $draft->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('record.editable', false));
        $this->put(route('accounting.sales-invoices.update', $draft->id), $this->invoiceData())->assertForbidden();
        $this->post(route('accounting.sales-invoices.post', $posted->id))->assertForbidden();
        $this->delete(route('accounting.sales-invoices.destroy', $draft->id))->assertForbidden();

        $this->signIn();
        $this->getJson(route('accounting.sales-invoices.info', $draft->id))->assertOk();
        $this->get(route('accounting.sales-invoices.pdf', $posted->id))->assertOk();
        $this->delete(route('accounting.sales-invoices.destroy', $posted->id))->assertSessionHas('error');
        $this->delete(route('accounting.sales-invoices.destroy', $draft->id))->assertRedirect(route('accounting.sales-invoices.index'));
        $this->assertSoftDeleted($draft);
    }
}
