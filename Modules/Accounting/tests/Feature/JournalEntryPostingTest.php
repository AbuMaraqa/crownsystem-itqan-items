<?php

namespace Modules\Accounting\tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Entities\AccountBalance;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Services\AccountService;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Entities\Currency;
use Tests\TestCase;

class JournalEntryPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_unbalanced_journal_entries(): void
    {
        [$cash, $sales, $currency] = $this->accountingFixture();

        $this->expectException(DomainException::class);

        app(JournalEntryService::class)->createDraft([
            'entry_date' => now()->toDateString(),
        ], [
            ['account_id' => $cash->id, 'currency_id' => $currency->id, 'exchange_rate' => 1, 'debit' => 100, 'credit' => 0],
            ['account_id' => $sales->id, 'currency_id' => $currency->id, 'exchange_rate' => 1, 'debit' => 0, 'credit' => 90],
        ]);
    }

    public function test_it_posts_balanced_entries_and_updates_balances(): void
    {
        [$cash, $sales, $currency] = $this->accountingFixture();

        $entry = app(JournalEntryService::class)->createDraft([
            'entry_date' => now()->toDateString(),
        ], [
            ['account_id' => $cash->id, 'currency_id' => $currency->id, 'exchange_rate' => 1, 'debit' => 100, 'credit' => 0],
            ['account_id' => $sales->id, 'currency_id' => $currency->id, 'exchange_rate' => 1, 'debit' => 0, 'credit' => 100],
        ]);

        app(JournalEntryService::class)->post($entry);

        $this->assertDatabaseHas(config('accounting.table_prefix').'journal_entries', [
            'id' => $entry->id,
            'status' => JournalEntryStatus::POSTED->value(),
        ]);

        $this->assertSame(2, AccountBalance::query()->count());
    }

    public function test_it_rejects_posting_to_closed_periods(): void
    {
        [$cash, $sales, $currency, $period] = $this->accountingFixture();

        $entry = app(JournalEntryService::class)->createDraft([
            'entry_date' => now()->toDateString(),
        ], [
            ['account_id' => $cash->id, 'currency_id' => $currency->id, 'exchange_rate' => 1, 'debit' => 100, 'credit' => 0],
            ['account_id' => $sales->id, 'currency_id' => $currency->id, 'exchange_rate' => 1, 'debit' => 0, 'credit' => 100],
        ]);

        $period->update(['status' => AccountingPeriodStatus::CLOSED->value()]);

        $this->expectException(DomainException::class);

        app(JournalEntryService::class)->post($entry);
    }

    public function test_a_deleted_draft_keeps_its_number_reserved(): void
    {
        [$cash, $sales, $currency] = $this->accountingFixture();
        $lines = [
            ['account_id' => $cash->id, 'currency_id' => $currency->id, 'exchange_rate' => 1, 'debit' => 100, 'credit' => 0],
            ['account_id' => $sales->id, 'currency_id' => $currency->id, 'exchange_rate' => 1, 'debit' => 0, 'credit' => 100],
        ];

        $deleted = app(JournalEntryService::class)->createDraft(['entry_date' => now()->toDateString()], $lines);
        $deleted->delete();

        $next = app(JournalEntryService::class)->createDraft(['entry_date' => now()->toDateString()], $lines);

        $this->assertNotSame($deleted->entry_number, $next->entry_number);
    }

    public function test_foreign_currency_entries_stay_balanced_in_base_currency(): void
    {
        [$cash, $sales, $currency] = $this->accountingFixture();

        // 13.70 + ضريبة 16% بسعر صرف 3.6523: تقريب كل سطر وحده يُخرج فرقاً قدره 0.0001.
        $entry = app(JournalEntryService::class)->createDraft([
            'entry_date' => now()->toDateString(),
        ], [
            ['account_id' => $cash->id, 'currency_id' => $currency->id, 'exchange_rate' => 3.6523, 'debit' => 15.892, 'credit' => 0],
            ['account_id' => $sales->id, 'currency_id' => $currency->id, 'exchange_rate' => 3.6523, 'debit' => 0, 'credit' => 13.70],
            ['account_id' => $sales->id, 'currency_id' => $currency->id, 'exchange_rate' => 3.6523, 'debit' => 0, 'credit' => 2.192],
        ]);

        $this->assertSame(
            round((float) $entry->lines->sum('debit_base'), 4),
            round((float) $entry->lines->sum('credit_base'), 4)
        );

        app(JournalEntryService::class)->post($entry);

        $this->assertSame(JournalEntryStatus::POSTED, $entry->refresh()->status);
    }

    public function test_journal_lines_keep_the_full_exchange_rate(): void
    {
        [$cash, $sales, $currency] = $this->accountingFixture();

        $entry = app(JournalEntryService::class)->createDraft([
            'entry_date' => now()->toDateString(),
        ], [
            ['account_id' => $cash->id, 'currency_id' => $currency->id, 'exchange_rate' => 0.02431234, 'debit' => 1000000, 'credit' => 0],
            ['account_id' => $sales->id, 'currency_id' => $currency->id, 'exchange_rate' => 0.02431234, 'debit' => 0, 'credit' => 1000000],
        ]);

        $line = $entry->lines->firstWhere('account_id', $cash->id);

        $this->assertSame('0.02431234', $line->exchange_rate);
        $this->assertSame('24312.3400', $line->debit_base);
    }

    private function accountingFixture(): array
    {
        $currency = Currency::create([
            'code' => 'USD',
            'symbol' => '$',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'exchange_rate' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);

        $year = FiscalYear::create([
            'name' => 'FY Test',
            'starts_at' => now()->startOfYear()->toDateString(),
            'ends_at' => now()->endOfYear()->toDateString(),
            'status' => AccountingPeriodStatus::OPEN->value(),
            'is_default' => true,
        ]);

        $period = AccountingPeriod::create([
            'fiscal_year_id' => $year->id,
            'name' => now()->format('F Y'),
            'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::OPEN->value(),
        ]);

        $service = app(AccountService::class);
        $cash = $service->create([
            'code' => '1110',
            'name' => 'Cash',
            'type' => AccountType::ASSET->value(),
            'is_postable' => true,
        ]);

        $sales = $service->create([
            'code' => '4100',
            'name' => 'Sales',
            'type' => AccountType::REVENUE->value(),
            'is_postable' => true,
        ]);

        return [$cash, $sales, $currency, $period];
    }
}
