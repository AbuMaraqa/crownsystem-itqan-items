<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\PeriodClosingVoucher;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PeriodClosingVoucherStatus;
use Modules\Core\Services\ReferenceNumberService;

/**
 * قيد اقفال السنة (Period Closing Voucher في ERPNext):
 *
 *   من حـ/ كل حساب ايراد (برصيده)            الى حـ/ الارباح المحتجزة
 *   من حـ/ الارباح المحتجزة                  الى حـ/ كل حساب مصروف (برصيده)
 *
 * بتاريخ آخر يوم في السنة ولكل فرع على حدة، فتبدأ السنة التالية بأرصدة ايرادات ومصروفات
 * صفرية. اذا رُحّلت قيود جديدة بعد قيد اقفال سابق (بعد اعادة فتح السنة) يُقفَل الفرق فقط.
 */
class PeriodClosingVoucherService
{
    private const EPSILON = 0.00005;

    /**
     * يُنشئ قيد الاقفال ويرحّله؛ يعيد null ان لم يكن في السنة ربح او خسارة غير مقفلة.
     */
    public function close(FiscalYear $year, ?string $notes = null): ?PeriodClosingVoucher
    {
        return DB::transaction(function () use ($year, $notes) {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->getKey());
            $closingDate = $year->ends_at->toDateString();
            $balances = $this->profitAndLossBalances($year);

            if ($balances === []) {
                return null;
            }

            $retainedAccountId = app(LedgerAccounts::class)->mapped(LedgerAccounts::RETAINED_EARNINGS_KEY);
            $currencyId = app(CurrencyExchangeRateService::class)->baseCurrencyId()
                ?? throw new DomainException(__('Base currency is not configured.'));
            $reference = app(ReferenceNumberService::class)->generate('period_closing_voucher', $closingDate, ['prefix' => 'PCV']);
            $description = __('Closing entry for fiscal year').' '.$year->name;

            $lines = [];
            $netByBranch = [];

            foreach ($balances as $balance) {
                $amount = round((float) $balance->balance, 4);
                $branchKey = (string) ($balance->branch_id ?? '');
                $netByBranch[$branchKey] = round(($netByBranch[$branchKey] ?? 0) + $amount, 4);

                // عكس رصيد الحساب: المدين يُقيَّد دائناً والعكس.
                $lines[] = $this->line((int) $balance->account_id, $balance->branch_id, $currencyId, $amount < 0 ? -$amount : 0, $amount > 0 ? $amount : 0, $description);
            }

            foreach ($netByBranch as $branchKey => $net) {
                if (abs($net) < self::EPSILON) {
                    continue;
                }

                // صافي مدين (خسارة) يُحمَّل على الارباح المحتجزة مديناً، والربح دائناً.
                $lines[] = $this->line($retainedAccountId, $branchKey === '' ? null : (int) $branchKey, $currencyId, $net > 0 ? $net : 0, $net < 0 ? -$net : 0, $description);
            }

            $netProfit = round(-array_sum($netByBranch), 4);

            $voucher = PeriodClosingVoucher::query()->create([
                'fiscal_year_id' => $year->id,
                'reference_no' => $reference,
                'status' => PeriodClosingVoucherStatus::POSTED->value,
                'closing_date' => $closingDate,
                'retained_earnings_account_id' => $retainedAccountId,
                'net_profit_base' => $netProfit,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);

            $journal = app(JournalEntryService::class);
            $entry = $journal->createDraft([
                'entry_date' => $closingDate,
                'reference_no' => $reference,
                'source_module' => 'Accounting',
                'source_type' => AccountingReportService::CLOSING_SOURCE_TYPE,
                'sourceable_id' => $voucher->id,
                'sourceable_type' => PeriodClosingVoucher::class,
                'description' => $description,
            ], $lines);

            $journal->post($entry);
            $voucher->update(['journal_entry_id' => $entry->id]);

            return $voucher->refresh();
        });
    }

    /**
     * الغاء قيد الاقفال (بعد اعادة فتح السنة) بقيد عكسي.
     */
    public function cancel(PeriodClosingVoucher $voucher): PeriodClosingVoucher
    {
        return DB::transaction(function () use ($voucher) {
            $voucher = PeriodClosingVoucher::query()->with(['journalEntry', 'fiscalYear'])->lockForUpdate()->findOrFail($voucher->getKey());

            if ($voucher->status !== PeriodClosingVoucherStatus::POSTED) {
                throw new DomainException(__('Only posted closing vouchers can be cancelled.'));
            }

            if ($voucher->fiscalYear?->status === AccountingPeriodStatus::CLOSED) {
                throw new DomainException(__('Reopen the fiscal year before cancelling its closing voucher.'));
            }

            if ($voucher->journalEntry && $voucher->journalEntry->status === JournalEntryStatus::POSTED) {
                app(JournalEntryService::class)->void($voucher->journalEntry, __('Closing voucher cancelled').' '.$voucher->reference_no);
            }

            $voucher->update([
                'status' => PeriodClosingVoucherStatus::CANCELLED->value,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
            ]);

            return $voucher->refresh();
        });
    }

    /**
     * ارصدة حسابات الايرادات والمصروفات في السنة لكل فرع (شاملة قيود اقفال سابقة).
     *
     * @return array<int, object{account_id:int, branch_id:?int, balance:float}>
     */
    private function profitAndLossBalances(FiscalYear $year): array
    {
        $lineTable = (new JournalLine)->getTable();
        $entryTable = (new JournalEntry)->getTable();
        $accountTable = (new Account)->getTable();

        return JournalLine::query()
            ->toBase()
            ->join("{$entryTable} as entries", 'entries.id', '=', "{$lineTable}.journal_entry_id")
            ->join("{$accountTable} as accounts", 'accounts.id', '=', "{$lineTable}.account_id")
            ->whereNull('entries.deleted_at')
            ->whereNull("{$lineTable}.deleted_at")
            ->whereIn('entries.status', [JournalEntryStatus::POSTED->value(), JournalEntryStatus::VOIDED->value()])
            ->whereBetween('entries.entry_date', [$year->starts_at->toDateString(), $year->ends_at->toDateString()])
            ->whereIn('accounts.type', [AccountType::REVENUE->value(), AccountType::EXPENSE->value()])
            ->groupBy("{$lineTable}.account_id", "{$lineTable}.branch_id")
            ->selectRaw("{$lineTable}.account_id, {$lineTable}.branch_id, ROUND(SUM({$lineTable}.debit_base - {$lineTable}.credit_base), 4) as balance")
            ->havingRaw("ABS(SUM({$lineTable}.debit_base - {$lineTable}.credit_base)) > 0.00005")
            ->get()
            ->all();
    }

    private function line(int $accountId, mixed $branchId, int $currencyId, float $debit, float $credit, string $description): array
    {
        return [
            'account_id' => $accountId,
            'branch_id' => $branchId,
            'currency_id' => $currencyId,
            'exchange_rate' => 1,
            'debit' => round($debit, 4),
            'credit' => round($credit, 4),
            'description' => $description,
        ];
    }
}
