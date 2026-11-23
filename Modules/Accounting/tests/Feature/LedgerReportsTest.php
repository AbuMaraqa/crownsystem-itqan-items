<?php

namespace Modules\Accounting\tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountBalance;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\PeriodClosingVoucher;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PeriodClosingVoucherStatus;
use Modules\Accounting\Services\AccountingReportService;
use Modules\Accounting\Services\AccountService;
use Modules\Accounting\Services\FiscalYearService;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Accounting\Services\PeriodClosingVoucherService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Tests\TestCase;

/**
 * الاستاذ العام والتقارير: ترقيم القيود، قواعد الشجرة، ميزان المراجعة بأرصدة افتتاحية،
 * توازن الميزانية مع ربح السنة، اقفال السنة الى الارباح المحتجزة، اقفال الفترات.
 */
class LedgerReportsTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private FiscalYear $lastYear;

    private FiscalYear $thisYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->currency = Currency::create([
            'code' => 'ILS',
            'symbol' => '₪',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);

        $years = app(FiscalYearService::class);
        $this->lastYear = $years->create(['name' => '2025', 'starts_at' => '2025-01-01', 'ends_at' => '2025-12-31']);
        $this->thisYear = $years->create(['name' => '2026', 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31', 'is_default' => true]);

        $this->seed(DefaultChartOfAccountsSeeder::class);
        $this->seed(AccountMappingSeeder::class);
    }

    public function test_fiscal_years_get_monthly_periods_and_cannot_overlap(): void
    {
        $this->assertSame(12, $this->thisYear->periods()->count());

        $this->expectException(DomainException::class);
        app(FiscalYearService::class)->create(['name' => 'Overlap', 'starts_at' => '2026-06-01', 'ends_at' => '2027-05-31']);
    }

    public function test_entry_numbers_follow_the_entry_month_and_never_repeat(): void
    {
        $first = $this->postEntry('2026-03-10', '1110', '4100', 100);
        $second = $this->postEntry('2026-03-11', '1110', '4100', 100);
        $other = $this->postEntry('2026-04-01', '1110', '4100', 100);

        $this->assertSame('JE-202603-00001', $first->entry_number);
        $this->assertSame('JE-202603-00002', $second->entry_number);
        $this->assertSame('JE-202604-00001', $other->entry_number);
    }

    public function test_an_entry_cannot_be_posted_twice(): void
    {
        $entry = $this->draft('2026-03-10', '1110', '4100', 100);
        $stale = JournalEntry::query()->find($entry->id);

        app(JournalEntryService::class)->post($entry);

        $this->expectException(DomainException::class);
        app(JournalEntryService::class)->post($stale);
    }

    public function test_tree_rules_keep_group_and_ledger_accounts_apart(): void
    {
        $receivables = $this->account('1130');
        $this->assertFalse($receivables->is_postable);

        $customer = User::factory()->create();
        $customerAccount = app(AccountService::class)->createForUser($customer, 'customer_account_parent');
        $this->assertSame($receivables->id, $customerAccount->parent_id);

        // فرع تحت حساب طرفي عليه قيود: مرفوض.
        $this->postEntry('2026-03-10', '1110', '4100', 100);

        try {
            app(AccountService::class)->create(['code' => '111001', 'parent_id' => $this->account('1110')->id, 'name' => 'Sub cash', 'type' => AccountType::ASSET->value(), 'is_postable' => true]);
            $this->fail('A child under a posted ledger account must be rejected.');
        } catch (DomainException) {
        }

        // تغيير نوع حساب عليه قيود: مرفوض.
        $this->expectException(DomainException::class);
        app(AccountService::class)->update($this->account('4100'), ['code' => '4100', 'name' => 'Sales', 'type' => AccountType::EXPENSE->value(), 'parent_id' => $this->account('4100')->parent_id, 'is_postable' => true]);
    }

    public function test_companies_we_pay_get_payable_accounts(): void
    {
        $accounts = app(AccountService::class);
        $payables = $this->account('2110');

        // شركة توصيل/شحن/تأمين/تخليص ومزود خدمة: ندفع لها، فحسابها تحت الذمم الدائنة لا المدينة.
        foreach (['Delivery Company', 'Shipping Company', 'Insurance Company', 'Clearance Company', 'Service Provider'] as $role) {
            $company = User::factory()->create();
            $accounts->syncForRoles($company, [$role]);
            $this->assertSame($payables->id, $company->refresh()->account->parent_id, $role);
        }

        // حساب انشئ قبل الدور (تحت المدينة) ولا قيود عليه ينتقل تحت الدائنة.
        $shipper = User::factory()->create();
        $accounts->createForUser($shipper);
        $this->assertSame($this->account('1130')->id, $shipper->refresh()->account->parent_id);
        $accounts->syncForRoles($shipper, ['Shipping Company']);
        $account = $shipper->refresh()->account;
        $this->assertSame($payables->id, $account->parent_id);
        $this->assertSame($payables->type, $account->type);
        $this->assertSame($payables->normal_balance, $account->normal_balance);
    }

    public function test_an_inactive_account_does_not_block_reversals_or_closing_the_year(): void
    {
        $sale = $this->postEntry('2025-05-01', '1110', '4100', 900);
        $this->postEntry('2025-06-01', '5410', '1110', 300);
        // الحسابات توقفت عن الاستعمال بعد قيودها.
        $this->account('4100')->update(['is_active' => false]);
        $this->account('5410')->update(['is_active' => false]);

        // قيد جديد على الحساب الموقوف ممنوع.
        try {
            $this->postEntry('2025-07-01', '1110', '4100', 10);
            $this->fail('A new entry on an inactive account must be rejected.');
        } catch (DomainException) {
        }

        // عكس قيد قديم عليه جائز، وكذلك اقفال السنة التي فيها ارصدته.
        $reversed = app(JournalEntryService::class)->void($sale, 'test');
        $this->assertTrue($reversed->hasReversal());
        $this->postEntry('2025-05-02', '1110', '4200', 900);

        app(FiscalYearService::class)->close($this->lastYear);

        $this->assertSame(PeriodClosingVoucherStatus::POSTED, PeriodClosingVoucher::query()->sole()->status);
    }

    public function test_balance_sheet_balances_with_the_unclosed_profit(): void
    {
        $this->postEntry('2026-02-01', '1110', '3100', 1000);   // رأس مال
        $this->postEntry('2026-03-01', '1110', '4100', 500);    // مبيعات نقدية
        $this->postEntry('2026-03-02', '5410', '1110', 200);    // مصروف

        $sheet = app(AccountingReportService::class)->balanceSheet('2026-12-31');

        $this->assertSame(1300.0, $sheet['total_assets']);
        $this->assertSame(300.0, $sheet['unclosed_profit']);
        $this->assertSame(1300.0, $sheet['total_equity']);
        $this->assertSame(0.0, $sheet['difference']);
        // حقوق الملكية تظهر موجبة (بطبيعتها الدائنة).
        $this->assertSame(1000.0, $sheet['equity']->firstWhere('code', '3100')['amount']);
    }

    public function test_trial_balance_shows_opening_movement_and_closing(): void
    {
        $this->postEntry('2026-02-01', '1110', '3100', 1000);
        $this->postEntry('2026-03-05', '1110', '4100', 400);

        $rows = app(AccountingReportService::class)->trialBalance('2026-03-01', '2026-03-31');
        $cash = $rows->firstWhere('code', '1110');
        $sales = $rows->firstWhere('code', '4100');

        $this->assertSame(1000.0, $cash['opening_base']);
        $this->assertSame(400.0, $cash['debit_base']);
        $this->assertSame(1400.0, $cash['balance_base']);
        $this->assertSame(0.0, $sales['opening_base']);
        $this->assertSame(-400.0, $sales['balance_base']);
    }

    public function test_closing_the_year_moves_profit_to_retained_earnings(): void
    {
        $this->postEntry('2025-05-01', '1110', '4100', 900);
        $this->postEntry('2025-06-01', '5410', '1110', 300);

        app(FiscalYearService::class)->close($this->lastYear);

        $voucher = PeriodClosingVoucher::query()->sole();
        $this->assertSame(PeriodClosingVoucherStatus::POSTED, $voucher->status);
        $this->assertSame('600.0000', $voucher->net_profit_base);
        $this->assertSame(AccountingPeriodStatus::CLOSED, $this->lastYear->refresh()->status);

        $reports = app(AccountingReportService::class);
        // قائمة دخل 2025 لا تتأثر بقيد الاقفال.
        $this->assertSame(600.0, $reports->incomeStatement('2025-01-01', '2025-12-31')['net_profit']);
        // ميزانية 2026: الربح صار ضمن الارباح المحتجزة، ولا ربح غير مقفل.
        $sheet = $reports->balanceSheet('2026-01-31');
        $this->assertSame(0.0, $sheet['unclosed_profit']);
        $this->assertSame(600.0, $sheet['equity']->firstWhere('code', '3200')['amount']);
        $this->assertSame(0.0, $sheet['difference']);
        // ميزان مراجعة 2026: رصيد المبيعات الافتتاحي صفر.
        $this->assertNull($reports->trialBalance('2026-01-01', '2026-01-31')->firstWhere('code', '4100'));

        // الترحيل في السنة المقفلة ممنوع.
        try {
            $this->postEntry('2025-07-01', '1110', '4100', 10);
            $this->fail('Posting into a closed year must be rejected.');
        } catch (DomainException) {
        }

        // الغاء قيد الاقفال يتطلب اعادة فتح السنة.
        try {
            app(PeriodClosingVoucherService::class)->cancel($voucher);
            $this->fail('Cancelling the closing voucher of a closed year must be rejected.');
        } catch (DomainException) {
        }

        app(FiscalYearService::class)->reopen($this->lastYear);
        app(PeriodClosingVoucherService::class)->cancel($voucher);
        $this->assertSame(600.0, $reports->balanceSheet('2026-01-31')['unclosed_profit']);
        // قيد عكس الاقفال لا يدخل قائمة الدخل كما لا يدخلها قيد الاقفال نفسه.
        $this->assertSame(600.0, $reports->incomeStatement('2025-01-01', '2025-12-31')['net_profit']);
    }

    public function test_a_closed_month_blocks_posting_and_reversal_moves_to_today(): void
    {
        $entry = $this->postEntry('2026-03-10', '1110', '4100', 100);
        $march = AccountingPeriod::query()->containsDate('2026-03-10')->sole();

        app(FiscalYearService::class)->closePeriod($march);

        try {
            $this->postEntry('2026-03-15', '1110', '4100', 50);
            $this->fail('Posting into a closed month must be rejected.');
        } catch (DomainException) {
        }

        // الالغاء ما زال ممكناً: القيد العكسي يُؤرَّخ اليوم (فترة مفتوحة).
        app(JournalEntryService::class)->void($entry);
        $reversal = JournalEntry::query()->where('reversal_of_entry_id', $entry->id)->sole();
        $this->assertSame(now()->toDateString(), $reversal->entry_date->toDateString());
        $this->assertSame(JournalEntryStatus::VOIDED, $entry->refresh()->status);
    }

    public function test_document_entries_cannot_be_voided_from_the_journal_screen(): void
    {
        $entry = $this->postEntry('2026-03-10', '1110', '4100', 100);
        $this->assertTrue($entry->canBeVoided());

        $entry->forceFill(['source_type' => 'SalesInvoice', 'sourceable_type' => 'x', 'sourceable_id' => 1])->save();
        $this->assertFalse($entry->refresh()->canBeVoided());
    }

    public function test_balances_summary_can_be_rebuilt_from_journal_lines(): void
    {
        $this->postEntry('2026-03-10', '1110', '4100', 100);
        $this->postEntry('2026-03-11', '1110', '4100', 50);
        AccountBalance::query()->update(['debit_base' => 999]);

        $this->artisan('accounting:rebuild-balances')->assertSuccessful();

        $cash = AccountBalance::query()->where('account_id', $this->account('1110')->id)->sole();
        $this->assertSame('150.0000', $cash->debit_base);
        $this->assertSame('150.0000', $cash->balance_base);
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

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }
}
