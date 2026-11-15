<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\Cheque;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\PaymentAllocation;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Entities\PeriodClosingVoucher;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPaymentMethod;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\ChequeStatus;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\NormalBalance;
use Modules\Accounting\Enums\PeriodClosingVoucherStatus;
use Modules\Accounting\Enums\ReceiptVoucherStatus;
use Modules\Accounting\Services\FiscalYearService;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Accounting\Services\PaymentVoucherService;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Services\ReceiptVoucherService;
use Modules\Accounting\Support\PaymentAllocationPanel;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * صفحات الاستاذ العام في لوحة Vue: دليل الحسابات، والقيود اليومية، والسنوات المالية وفتراتها،
 * وقيود الاقفال، والشيكات، ولوحة تخصيص الدفعات؛ القوائم والنماذج والانتقالات بصلاحياتها.
 */
class LedgerPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'Account View List', 'Account Create', 'Account Update', 'Account Delete', 'Account View', 'Account Info',
        'Journal Entry View List', 'Journal Entry Create', 'Journal Entry Update', 'Journal Entry Delete',
        'Journal Entry View', 'Journal Entry Info', 'Journal Entry Post', 'Journal Entry Void',
        'Fiscal Year View List', 'Fiscal Year Create', 'Fiscal Year Update', 'Fiscal Year View', 'Fiscal Year Info',
        'Fiscal Year Close', 'Fiscal Year Reopen', 'Accounting Period Close', 'Accounting Period Reopen',
        'Period Closing Voucher View List', 'Period Closing Voucher Cancel',
        'Cheque View List', 'Cheque View', 'Cheque Info', 'Cheque Clear', 'Cheque Bounce', 'Cheque Cancel',
        'Payment Allocation Create', 'Payment Allocation Delete',
    ];

    private Currency $currency;

    private FiscalYear $lastYear;

    private FiscalYear $thisYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->signIn();

        $this->currency = Currency::create([
            'code' => 'ILS',
            'symbol' => '₪',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);

        $year = now()->year;
        $years = app(FiscalYearService::class);
        $this->lastYear = $years->create(['name' => (string) ($year - 1), 'starts_at' => ($year - 1).'-01-01', 'ends_at' => ($year - 1).'-12-31']);
        $this->thisYear = $years->create(['name' => (string) $year, 'starts_at' => $year.'-01-01', 'ends_at' => $year.'-12-31', 'is_default' => true]);

        $this->seed(DefaultChartOfAccountsSeeder::class);
        $this->seed(AccountMappingSeeder::class);
    }

    private function signIn(array $permissions = self::PERMISSIONS): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    // ------------------------------------------------------------ chart of accounts

    public function test_the_chart_of_accounts_is_an_inertia_tree_with_filters(): void
    {
        $this->get(route('accounting.accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Account/Index')
                ->where('totalCount', Account::query()->count())
                ->where('expandAll', false)
                ->where('filters.active', true)
                ->where('tree.0.code', '1000')
                ->has('types', count(AccountType::cases())));

        // البحث يُظهر الحساب المطابق مع مساره حتى الجذر، ويفتح الفروع.
        $this->get(route('accounting.accounts.index', ['filter' => ['search' => '1110']]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('expandAll', true)
                ->where('visibleCount', 3)
                ->where('tree.0.code', '1000')
                ->where('tree.0.children.0.code', '1100')
                ->where('tree.0.children.0.children.0.code', '1110'));
    }

    public function test_an_account_is_added_under_a_parent_and_updated(): void
    {
        $parent = $this->account('1100');

        $this->get(route('accounting.accounts.add', ['parent_id' => $parent->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Account/Add')
                ->where('account.parent_id', $parent->id)
                ->where('account.type', AccountType::ASSET->value())
                ->where('account.normal_balance', NormalBalance::DEBIT->value())
                ->has('parents'));

        $this->post(route('accounting.accounts.store'), [
            'code' => 'T-100',
            'name' => 'صندوق الفرع',
            'parent_id' => $parent->id,
            'type' => AccountType::ASSET->value(),
            'normal_balance' => NormalBalance::DEBIT->value(),
            'is_postable' => true,
            'is_active' => true,
        ])->assertRedirect(route('accounting.accounts.index'));

        $account = Account::query()->where('code', 'T-100')->firstOrFail();
        $this->assertSame('صندوق الفرع', $account->translate('ar')->name);
        $this->assertSame($parent->level + 1, $account->level);

        $this->get(route('accounting.accounts.edit', $account->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Account/Edit')
                ->where('account.code', 'T-100')
                ->where('disabledParents', [$account->id]));

        $this->put(route('accounting.accounts.update', $account->id), [
            'code' => 'T-100',
            'name' => 'صندوق الفرع الرئيسي',
            'parent_id' => $parent->id,
            'type' => AccountType::ASSET->value(),
            'normal_balance' => NormalBalance::DEBIT->value(),
            'is_postable' => true,
            'is_active' => true,
        ])->assertRedirect(route('accounting.accounts.index'));

        $this->assertSame('صندوق الفرع الرئيسي', $account->refresh()->translate('ar')->name);

        // رقم مكرر يُرفض.
        $this->post(route('accounting.accounts.store'), [
            'code' => 'T-100', 'name' => 'x', 'type' => AccountType::ASSET->value(), 'normal_balance' => NormalBalance::DEBIT->value(),
        ])->assertSessionHasErrors('code');
    }

    public function test_an_account_with_children_or_entries_is_not_deleted(): void
    {
        $group = $this->account('1100');

        $this->delete(route('accounting.accounts.destroy', $group->id))
            ->assertSessionHas('error', __('Cannot delete an account that has child accounts.'));
        $this->assertNotSoftDeleted($group);

        $this->postEntry(now()->toDateString(), '1110', '4100', 50);
        $this->delete(route('accounting.accounts.destroy', $this->account('1110')->id))
            ->assertSessionHas('error', __('Cannot delete an account that has journal lines.'));

        $leaf = $this->account('1120');
        $this->delete(route('accounting.accounts.destroy', $leaf->id))->assertSessionHas('success');
        $this->assertSoftDeleted($leaf);
    }

    // ------------------------------------------------------------ journal entries

    public function test_a_balanced_entry_is_created_listed_posted_and_voided(): void
    {
        $this->post(route('accounting.journal-entries.store'), $this->entryPayload(100, 100))
            ->assertRedirect(route('accounting.journal-entries.index'));

        $entry = JournalEntry::query()->sole();
        $this->assertSame(JournalEntryStatus::DRAFT, $entry->status);
        $this->assertSame(2, $entry->lines()->count());

        $this->get(route('accounting.journal-entries.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/JournalEntry/Index')
                ->has('table.data', 1)
                ->where('table.data.0.entry_number', $entry->entry_number)
                ->where('table.data.0.debit_total', '100')
                ->where('table.data.0.is_draft', true));

        $this->post(route('accounting.journal-entries.post', $entry->id))->assertSessionHas('success');
        $this->assertSame(JournalEntryStatus::POSTED, $entry->refresh()->status);

        $this->post(route('accounting.journal-entries.void', $entry->id))->assertSessionHas('success');
        $this->assertSame(JournalEntryStatus::VOIDED, $entry->refresh()->status);
        $this->assertSame(1, JournalEntry::query()->where('reversal_of_entry_id', $entry->id)->count());

        // المرحّل لا يُرحَّل مرة ثانية.
        $this->post(route('accounting.journal-entries.post', $entry->id))
            ->assertSessionHas('error', __('Only draft journal entries can be posted.'));
    }

    public function test_an_unbalanced_or_short_entry_is_refused(): void
    {
        $this->post(route('accounting.journal-entries.store'), $this->entryPayload(100, 60))
            ->assertSessionHas('error', __('Journal entry debit and credit totals must match.'));
        $this->assertSame(0, JournalEntry::query()->count());

        $payload = $this->entryPayload(100, 100);
        $payload['lines'] = [$payload['lines'][0]];
        $this->post(route('accounting.journal-entries.store'), $payload)->assertSessionHasErrors('lines');
    }

    public function test_a_draft_is_edited_and_deleted_while_a_posted_entry_is_read_only(): void
    {
        $draft = $this->draft(now()->toDateString(), '1110', '4100', 100);

        $this->get(route('accounting.journal-entries.edit', $draft->id))
            ->assertInertia(fn (Assert $page) => $page->component('Accounting/JournalEntry/Edit')->has('entry.lines', 2));

        $this->put(route('accounting.journal-entries.update', $draft->id), $this->entryPayload(250, 250, 'تسوية'))
            ->assertRedirect(route('accounting.journal-entries.index'));
        $this->assertSame('تسوية', $draft->refresh()->reference_no);
        $this->assertSame(250.0, (float) $draft->lines()->sum('debit'));

        $posted = $this->postEntry(now()->toDateString(), '1110', '4100', 40);

        $this->get(route('accounting.journal-entries.edit', $posted->id))
            ->assertRedirect(route('accounting.journal-entries.show', $posted->id));
        $this->get(route('accounting.journal-entries.show', $posted->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/JournalEntry/Show')
                ->where('entry.can_void', true)
                ->where('entry.debit_total', '40')
                ->has('entry.lines', 2));

        $this->delete(route('accounting.journal-entries.destroy', $posted->id))
            ->assertSessionHas('error', __('Only draft journal entries can be deleted.'));
        $this->assertNotSoftDeleted($posted);

        $this->delete(route('accounting.journal-entries.destroy', $draft->id))->assertSessionHas('success');
        $this->assertSoftDeleted($draft);
    }

    public function test_a_document_entry_cannot_be_voided_from_the_journal_screen(): void
    {
        $entry = $this->postEntry(now()->toDateString(), '1110', '4100', 100);
        $entry->forceFill(['source_type' => 'SalesInvoice', 'sourceable_type' => 'x', 'sourceable_id' => 1])->save();

        $this->post(route('accounting.journal-entries.void', $entry->id))->assertSessionHas('error');
        $this->assertSame(JournalEntryStatus::POSTED, $entry->refresh()->status);
    }

    // ------------------------------------------------------------ fiscal years

    public function test_fiscal_years_are_added_edited_closed_and_reopened(): void
    {
        $this->get(route('accounting.fiscal-years.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/FiscalYear/Index')
                ->has('table.data', 2)
                ->where('table.data.0.name', $this->thisYear->name)
                ->where('table.data.0.periods_count', 12));

        $next = now()->year + 1;
        $this->post(route('accounting.fiscal-years.store'), ['name' => (string) $next, 'starts_at' => $next.'-01-01', 'ends_at' => $next.'-12-31'])
            ->assertSessionHas('success');
        $added = FiscalYear::query()->where('name', (string) $next)->sole();
        $this->assertSame(12, $added->periods()->count());

        // سنة تتداخل مع اخرى تُرفض تحت حقل البداية، والنهاية قبل البداية تُرفض.
        $this->post(route('accounting.fiscal-years.store'), ['name' => 'x', 'starts_at' => $next.'-06-01', 'ends_at' => ($next + 1).'-05-31'])
            ->assertSessionHasErrors('starts_at');
        $this->post(route('accounting.fiscal-years.store'), ['name' => 'x', 'starts_at' => $next.'-06-01', 'ends_at' => $next.'-01-01'])
            ->assertSessionHasErrors('ends_at');

        $this->put(route('accounting.fiscal-years.update', $added->id), ['name' => 'FY '.$next, 'starts_at' => $next.'-01-01', 'ends_at' => $next.'-12-31', 'is_default' => true])
            ->assertSessionHas('success');
        $this->assertSame('FY '.$next, $added->refresh()->name);
        $this->assertTrue($added->is_default);
        $this->assertFalse($this->thisYear->refresh()->is_default);

        $this->post(route('accounting.fiscal-years.close', $this->lastYear->id))->assertSessionHas('success');
        $this->assertSame(AccountingPeriodStatus::CLOSED, $this->lastYear->refresh()->status);
        $this->assertSame(0, $this->lastYear->periods()->where('status', AccountingPeriodStatus::OPEN->value())->count());

        $this->post(route('accounting.fiscal-years.reopen', $this->lastYear->id))->assertSessionHas('success');
        $this->assertSame(AccountingPeriodStatus::OPEN, $this->lastYear->refresh()->status);
    }

    public function test_monthly_periods_are_listed_closed_and_reopened(): void
    {
        $this->get(route('accounting.fiscal-years.periods', $this->thisYear->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/FiscalYear/Periods')
                ->where('fiscalYear.id', $this->thisYear->id)
                ->has('table.data', 12));

        $period = AccountingPeriod::query()->containsDate(now()->toDateString())->sole();

        // مسودة في الشهر تمنع اقفاله.
        $draft = $this->draft(now()->toDateString(), '1110', '4100', 10);
        $this->post(route('accounting.fiscal-years.periods.close', $period->id))->assertSessionHas('error');
        $this->assertSame(AccountingPeriodStatus::OPEN, $period->refresh()->status);

        app(JournalEntryService::class)->delete($draft);
        $this->post(route('accounting.fiscal-years.periods.close', $period->id))->assertSessionHas('success');
        $this->assertSame(AccountingPeriodStatus::CLOSED, $period->refresh()->status);

        $this->post(route('accounting.fiscal-years.periods.reopen', $period->id))->assertSessionHas('success');
        $this->assertSame(AccountingPeriodStatus::OPEN, $period->refresh()->status);
    }

    // ------------------------------------------------------------ closing vouchers

    public function test_the_closing_voucher_is_listed_and_cancelled_only_after_reopening_the_year(): void
    {
        $this->postEntry(($this->lastYear->starts_at->year).'-05-01', '1110', '4100', 900);
        $this->post(route('accounting.fiscal-years.close', $this->lastYear->id))->assertSessionHas('success');

        $voucher = PeriodClosingVoucher::query()->sole();

        $this->get(route('accounting.period-closing-vouchers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/PeriodClosingVoucher/Index')
                ->has('table.data', 1)
                ->where('table.data.0.reference_no', $voucher->reference_no)
                ->where('table.data.0.net_profit', '900')
                ->where('table.data.0.is_posted', true));

        $this->post(route('accounting.period-closing-vouchers.cancel', $voucher->id))
            ->assertSessionHas('error', __('Reopen the fiscal year before cancelling its closing voucher.'));
        $this->assertSame(PeriodClosingVoucherStatus::POSTED, $voucher->refresh()->status);

        $this->post(route('accounting.fiscal-years.reopen', $this->lastYear->id));
        $this->post(route('accounting.period-closing-vouchers.cancel', $voucher->id))->assertSessionHas('success');
        $this->assertSame(PeriodClosingVoucherStatus::CANCELLED, $voucher->refresh()->status);

        $this->getJson(route('accounting.period-closing-vouchers.info', $voucher->id))->assertOk()->assertJsonStructure(['created_by', 'activities']);
    }

    // ------------------------------------------------------------ cheques

    public function test_cheques_are_listed_cleared_and_bounced(): void
    {
        $customer = User::factory()->create(['name' => 'زبون الشيكات']);
        $first = $this->postReceipt($customer, 100, 'IN-1');
        $second = $this->postReceipt($customer, 70, 'IN-2');

        $this->get(route('accounting.cheques.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Cheque/Index')
                ->has('table.data', 2)
                ->where('table.data.0.is_under_collection', true)
                ->has('bankAccounts'));

        $this->get(route('accounting.cheques.index', ['filter' => ['cheque_number' => 'IN-2']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.cheque_number', 'IN-2'));

        $firstCheque = $this->chequeOf($first);
        $secondCheque = $this->chequeOf($second);

        $this->post(route('accounting.cheques.clear', $firstCheque->id), [])->assertSessionHasErrors('clearing_account_id');

        $this->post(route('accounting.cheques.clear', $firstCheque->id), ['clearing_account_id' => $this->account('1120')->id])
            ->assertSessionHas('success');
        $this->assertSame(ChequeStatus::CLEARED, $firstCheque->refresh()->status);
        $this->assertNotNull($firstCheque->journal_entry_id);

        // المحصَّل لا يُرتجع.
        $this->post(route('accounting.cheques.bounce', $firstCheque->id))->assertSessionHas('error');
        $this->assertSame(ChequeStatus::CLEARED, $firstCheque->refresh()->status);

        $this->post(route('accounting.cheques.bounce', $secondCheque->id))->assertSessionHas('success');
        $this->assertSame(ChequeStatus::BOUNCED, $secondCheque->refresh()->status);
        $this->assertSame(ReceiptVoucherStatus::CANCELLED, $second->refresh()->status);
    }

    // ------------------------------------------------------------ payment allocation

    public function test_payments_are_allocated_and_removed_from_the_panel(): void
    {
        $supplier = User::factory()->create(['name' => 'مورد التخصيص']);
        $invoice = $this->postPurchaseInvoice($supplier, 10, 100);
        $voucher = $this->postPayment($supplier, 500);

        $panel = PaymentAllocationPanel::of($voucher);
        $this->assertFalse($panel['is_invoice']);
        $this->assertTrue($panel['can_allocate']);
        $this->assertSame(['type' => 'payment-vouchers', 'id' => $voucher->id], $panel['source']);
        $this->assertCount(1, $panel['open_invoices']);
        $this->assertSame('500', $panel['open_invoices'][0]['suggested']);

        $source = ['sourceType' => 'payment-vouchers', 'source' => $voucher->id];

        $this->post(route('accounting.payment-allocations.allocate', $source), ['invoice_id' => $invoice->id, 'amount' => '200'])
            ->assertSessionHas('success');
        $this->assertSame(800.0, (float) $invoice->refresh()->outstanding_amount);

        // اكثر من غير المخصَّص يُرفض.
        $this->post(route('accounting.payment-allocations.allocate', $source), ['invoice_id' => $invoice->id, 'amount' => '400'])
            ->assertSessionHas('error');
        $this->post(route('accounting.payment-allocations.allocate', $source), ['invoice_id' => 999999])
            ->assertSessionHas('error', __('The invoice is no longer open.'));

        $this->post(route('accounting.payment-allocations.auto', $source))->assertSessionHas('success');
        $this->assertSame(500.0, (float) $invoice->refresh()->paid_amount);
        $this->assertSame(500.0, (float) $voucher->refresh()->allocated_amount);

        $invoicePanel = PaymentAllocationPanel::of($invoice);
        $this->assertTrue($invoicePanel['is_invoice']);
        $this->assertFalse($invoicePanel['can_allocate']);
        $this->assertCount(2, $invoicePanel['allocations']);

        $allocation = PaymentAllocation::query()->oldest('id')->firstOrFail();
        $this->delete(route('accounting.payment-allocations.destroy', $allocation->id))->assertSessionHas('success');
        $this->assertSame(700.0, (float) $invoice->refresh()->outstanding_amount);

        $this->post(route('accounting.payment-allocations.allocate', ['sourceType' => 'unknown', 'source' => $voucher->id]), ['invoice_id' => $invoice->id])
            ->assertNotFound();
    }

    // ------------------------------------------------------------ permissions

    public function test_every_page_and_action_needs_its_permission(): void
    {
        $entry = $this->draft(now()->toDateString(), '1110', '4100', 10);
        $account = $this->account('1120');
        $voucher = PeriodClosingVoucher::query()->create([
            'fiscal_year_id' => $this->lastYear->id,
            'reference_no' => 'PCV-'.Str::random(5),
            'status' => PeriodClosingVoucherStatus::POSTED->value,
            'closing_date' => $this->lastYear->ends_at->toDateString(),
            'retained_earnings_account_id' => $this->account('3200')->id,
            'net_profit_base' => 0,
        ]);

        $this->signIn([]);

        $this->get(route('accounting.accounts.index'))->assertForbidden();
        $this->get(route('accounting.journal-entries.index'))->assertForbidden();
        $this->get(route('accounting.fiscal-years.index'))->assertForbidden();
        $this->get(route('accounting.period-closing-vouchers.index'))->assertForbidden();
        $this->get(route('accounting.cheques.index'))->assertForbidden();

        // من يرى القوائم فقط لا يغيّر شيئاً.
        $this->signIn(['Account View List', 'Journal Entry View List', 'Fiscal Year View List', 'Period Closing Voucher View List', 'Cheque View List']);

        $this->get(route('accounting.accounts.index'))->assertOk();
        $this->get(route('accounting.accounts.add'))->assertForbidden();
        $this->delete(route('accounting.accounts.destroy', $account->id))->assertForbidden();
        $this->get(route('accounting.journal-entries.edit', $entry->id))->assertForbidden();
        $this->post(route('accounting.journal-entries.post', $entry->id))->assertForbidden();
        $this->delete(route('accounting.journal-entries.destroy', $entry->id))->assertForbidden();
        $this->post(route('accounting.fiscal-years.store'), [])->assertForbidden();
        $this->post(route('accounting.fiscal-years.close', $this->lastYear->id))->assertForbidden();
        $this->get(route('accounting.fiscal-years.periods', $this->thisYear->id))->assertOk();
        $this->post(route('accounting.fiscal-years.periods.close', AccountingPeriod::query()->value('id')))->assertForbidden();
        $this->post(route('accounting.period-closing-vouchers.cancel', $voucher->id))->assertForbidden();
        $this->post(route('accounting.cheques.clear', 1), ['clearing_account_id' => $account->id])->assertForbidden();
        $this->post(route('accounting.payment-allocations.auto', ['sourceType' => 'payment-vouchers', 'source' => 1]))->assertForbidden();
        $this->delete(route('accounting.payment-allocations.destroy', 1))->assertForbidden();

        $this->assertSame(JournalEntryStatus::DRAFT, $entry->refresh()->status);
        $this->assertNotSoftDeleted($account);
        $this->assertSame(AccountingPeriodStatus::OPEN, $this->lastYear->refresh()->status);
    }

    // ---------------------------------------------------------------- helpers

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function entryPayload(float $debit, float $credit, string $reference = 'REF-1'): array
    {
        $line = fn (string $code, float $debit, float $credit) => [
            'account_id' => $this->account($code)->id,
            'branch_id' => null,
            'currency_id' => $this->currency->id,
            'exchange_rate' => '1',
            'debit' => $debit,
            'credit' => $credit,
            'description' => '',
        ];

        return [
            'entry_date' => now()->toDateString(),
            'reference_no' => $reference,
            'description' => 'قيد يدوي',
            'lines' => [$line('1110', $debit, 0), $line('4100', 0, $credit)],
        ];
    }

    private function draft(string $date, string $debitCode, string $creditCode, float $amount): JournalEntry
    {
        return app(JournalEntryService::class)->createDraft(['entry_date' => $date], [
            ['account_id' => $this->account($debitCode)->id, 'currency_id' => $this->currency->id, 'exchange_rate' => 1, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $this->account($creditCode)->id, 'currency_id' => $this->currency->id, 'exchange_rate' => 1, 'debit' => 0, 'credit' => $amount],
        ]);
    }

    private function postEntry(string $date, string $debitCode, string $creditCode, float $amount): JournalEntry
    {
        return app(JournalEntryService::class)->post($this->draft($date, $debitCode, $creditCode, $amount));
    }

    private function postReceipt(User $customer, float $amount, string $chequeNumber): ReceiptVoucher
    {
        $service = app(ReceiptVoucherService::class);

        return $service->post($service->create([
            'client_id' => $customer->id,
            'account_id' => $this->account('1110')->id,
            'voucher_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'payment_method' => AccountingPaymentMethod::CHEQUE->value(),
            'cheque_number' => $chequeNumber,
            'cheque_date' => now()->toDateString(),
            'amount' => $amount,
        ]));
    }

    private function chequeOf(ReceiptVoucher $voucher): Cheque
    {
        return Cheque::query()->where('chequeable_type', ReceiptVoucher::class)->where('chequeable_id', $voucher->id)->sole();
    }

    private function postPayment(User $supplier, float $amount): PaymentVoucher
    {
        $service = app(PaymentVoucherService::class);

        return $service->post($service->create([
            'client_id' => $supplier->id,
            'account_id' => $this->account('1110')->id,
            'voucher_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'payment_method' => AccountingPaymentMethod::CASH->value(),
            'amount' => $amount,
        ]));
    }

    private function postPurchaseInvoice(User $supplier, float $quantity, float $price): PurchaseInvoice
    {
        $warehouse = Warehouse::create(['code' => 'WH-'.Str::random(4), 'is_active' => true]);
        $warehouse->translateOrNew('ar')->name = 'المخزن الرئيسي';
        $warehouse->save();

        $product = new Product([
            'slug' => 'ledger-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => 'B-'.Str::random(8),
            'base_price' => 10,
            'cost_price' => 8,
            'sale_price' => 20,
            'pricing_type' => 'simple',
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = 'صنف التخصيص';
        $product->save();

        $service = app(PurchaseInvoiceService::class);

        return $service->post($service->create([
            'client_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price]],
        ]));
    }
}
