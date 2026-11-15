<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\SalesReturnStatus;
use Modules\Accounting\Services\InventoryAccounts;
use Modules\Accounting\tests\Feature\Concerns\SalesDocumentFixtures;
use Modules\Core\Entities\User;
use Tests\TestCase;

/**
 * مرتجعات البيع في لوحة Vue: المرتجع الجديد من فاتورة مرحّلة للزبون يأخذ كل القابل للارجاع، ثم
 * يُدخل عدد القطع المرتجعة بحد ما فوتر (ReturnPieces)، والترحيل (عودة القطع بكلفتها، قيد
 * المخزون، وتسديد الفاتورة)، والالغاء، ولوحة التخصيص، وكل اجراء بصلاحيته.
 */
class SalesReturnPagesTest extends TestCase
{
    use RefreshDatabase;
    use SalesDocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSalesDocuments();
    }

    private function storedReturn(int $pieces = 3): SalesReturn
    {
        $invoice = $this->postedInvoice($pieces);

        $this->post(route('accounting.sales-returns.store'), [
            'client_id' => $this->customer->id,
            'sales_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'warehouse_id' => null,
            'notes' => 'مرتجع',
        ])->assertRedirect()->assertSessionHas('success');

        return SalesReturn::query()->latest('id')->firstOrFail();
    }

    public function test_a_new_return_takes_everything_returnable_of_a_posted_invoice(): void
    {
        $this->get(route('accounting.sales-returns.add'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Accounting/SalesReturn/Add')->has('referenceNo'));

        $invoice = $this->postedInvoice(3);
        $other = User::factory()->create();

        $this->getJson(route('accounting.sales-returns.invoices', ['client_id' => $this->customer->id]))
            ->assertOk()
            ->assertJsonPath('0.value', $invoice->id);
        $this->getJson(route('accounting.sales-returns.invoices', ['client_id' => $other->id]))->assertOk()->assertJsonCount(0);
        $this->getJson(route('accounting.sales-returns.invoice-defaults', ['sales_invoice_id' => $invoice->id]))
            ->assertOk()
            ->assertJsonPath('warehouse_id', $this->warehouse->id)
            ->assertJsonPath('currency', 'ILS');

        // فاتورة زبون آخر لا تُختار.
        $this->post(route('accounting.sales-returns.store'), [
            'client_id' => $other->id,
            'sales_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
        ])->assertSessionHasErrors('sales_invoice_id');

        $return = $this->storedReturn(3);

        // 3 قطع × 6 × 1.2 = 21.6 كغم × 20.
        $this->assertSame(432.0, (float) $return->total_amount);
        $this->assertSame(SalesReturnStatus::DRAFT, $return->status);
    }

    public function test_the_page_shows_returnable_pieces_and_saves_only_within_the_invoiced_count(): void
    {
        $return = $this->storedReturn(3);

        $response = $this->get(route('accounting.sales-returns.edit', $return->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/SalesReturn/Edit')
                ->where('record.editable', true)
                ->where('record.sales_invoice.id', $return->sales_invoice_id)
                ->where('salesReturn.items.0.pricing_type', 'weight')
                ->where('allocationPanel', null));

        $form = $this->props($response)['salesReturn'];
        $this->assertSame(3.0, (float) $form['items'][0]['pieces'][0]['max_piece_count']);
        $this->assertSame(3.0, (float) $form['items'][0]['pieces'][0]['piece_count']);

        $form['items'][0]['pieces'][0]['piece_count'] = 4;
        $this->put(route('accounting.sales-returns.update', $return->id), $form)->assertSessionHas('error');

        $form['items'][0]['pieces'][0]['piece_count'] = 1;
        $this->put(route('accounting.sales-returns.update', $return->id), $form)->assertSessionHas('success');

        // قطعة من ثلاث: 7.2 كغم × 20.
        $return->refresh();
        $this->assertSame(144.0, (float) $return->total_amount);
        $this->assertSame(1.0, (float) $return->items()->sole()->pieces()->sum('piece_count'));
    }

    public function test_posting_returns_the_pieces_to_stock_settles_the_invoice_and_cancelling_reverses_it(): void
    {
        $return = $this->storedReturn(3);
        $sub = $this->product('DEMO-1025-01');
        $afterSale = $this->onHand($sub);

        $form = $this->props($this->get(route('accounting.sales-returns.edit', $return->id)))['salesReturn'];
        $form['items'][0]['pieces'][0]['piece_count'] = 1;

        $this->post(route('accounting.sales-returns.post', $return->id), $form)->assertSessionHas('success');

        $return->refresh();
        $this->assertSame(SalesReturnStatus::POSTED, $return->status);
        $this->assertSame($afterSale + 1, $this->onHand($sub));
        $inventoryEntry = JournalEntry::query()
            ->where('sourceable_type', $return::class)
            ->where('sourceable_id', $return->id)
            ->where('source_module', InventoryAccounts::SOURCE_MODULE)
            ->firstOrFail();
        $this->assertSame(JournalEntryStatus::POSTED, $inventoryEntry->status);
        // المرتجع يسدد فاتورته اولاً: 432 − 144.
        $this->assertSame(288.0, (float) $return->salesInvoice->refresh()->outstanding_amount);

        $this->get(route('accounting.sales-returns.edit', $return->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.editable', false)
                ->where('record.can_cancel', true)
                ->where('allocationPanel.is_invoice', false)
                ->where('allocationPanel.allocated', '144')
                ->has('allocationPanel.allocations', 1));

        $this->post(route('accounting.sales-returns.cancel', $return->id), ['reason' => 'خطأ'])->assertSessionHas('success');

        $return->refresh();
        $this->assertSame(SalesReturnStatus::CANCELLED, $return->status);
        $this->assertSame('خطأ', $return->cancellation_reason);
        $this->assertSame($afterSale, $this->onHand($sub));
        $this->assertSame(JournalEntryStatus::VOIDED, $inventoryEntry->refresh()->status);
        $this->assertSame(432.0, (float) $return->salesInvoice->refresh()->outstanding_amount);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $return = $this->storedReturn();

        $this->signIn(['Sales Return View List']);
        $this->get(route('accounting.sales-returns.index'))->assertOk();
        $this->get(route('accounting.sales-returns.add'))->assertForbidden();
        $this->get(route('accounting.sales-returns.edit', $return->id))->assertForbidden();
        $this->put(route('accounting.sales-returns.update', $return->id), ['return_date' => now()->toDateString()])->assertForbidden();
        $this->post(route('accounting.sales-returns.post', $return->id))->assertForbidden();
        $this->post(route('accounting.sales-returns.cancel', $return->id))->assertForbidden();
        $this->delete(route('accounting.sales-returns.destroy', $return->id))->assertForbidden();

        $this->signIn([]);
        $this->get(route('accounting.sales-returns.index'))->assertForbidden();
        $this->assertSame(SalesReturnStatus::DRAFT, $return->refresh()->status);
    }

    public function test_the_list_info_pdf_and_delete(): void
    {
        $return = $this->storedReturn();

        $this->get(route('accounting.sales-returns.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/SalesReturn/Index')
                ->has('table.data', 1)
                ->where('table.data.0.editable', true)
                ->where('table.data.0.sales_invoice', $return->salesInvoice->reference_no));
        $this->get(route('accounting.sales-returns.index', ['filter' => ['status' => SalesReturnStatus::POSTED->value()]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 0));
        $this->get(route('accounting.sales-returns.index', ['filter' => ['sales_invoice' => $return->salesInvoice->reference_no]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1));

        $this->getJson(route('accounting.sales-returns.info', $return->id))->assertOk();
        $this->get(route('accounting.sales-returns.pdf', $return->id))->assertOk();

        $this->delete(route('accounting.sales-returns.destroy', $return->id))->assertRedirect(route('accounting.sales-returns.index'));
        $this->assertSoftDeleted($return);
    }
}
