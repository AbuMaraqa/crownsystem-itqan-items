<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountBalance;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Core\Services\ReferenceNumberService;

class JournalEntryService
{
    public function createDraft(array $data, array $lines): JournalEntry
    {
        return DB::transaction(function () use ($data, $lines) {
            $period = $this->resolveOpenPeriod($data['entry_date']);

            $entry = JournalEntry::create([
                'entry_number' => $data['entry_number'] ?? $this->nextEntryNumber($data['entry_date']),
                'fiscal_year_id' => $period->fiscal_year_id,
                'accounting_period_id' => $period->id,
                'entry_date' => $data['entry_date'],
                'status' => JournalEntryStatus::DRAFT->value(),
                'reference_no' => $data['reference_no'] ?? null,
                'source_module' => $data['source_module'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'sourceable_id' => $data['sourceable_id'] ?? null,
                'sourceable_type' => $data['sourceable_type'] ?? null,
                'reversal_of_entry_id' => $data['reversal_of_entry_id'] ?? null,
                'description' => $data['description'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $this->replaceLines($entry, $lines);

            return $entry->load(['lines.account.translations', 'lines.branch', 'lines.currency']);
        });
    }


    public function updateDraft(JournalEntry $entry, array $data, array $lines): JournalEntry
    {
        if ($entry->status !== JournalEntryStatus::DRAFT) {
            throw new DomainException(__('Only draft journal entries can be updated.'));
        }

        return DB::transaction(function () use ($entry, $data, $lines) {
            $period = $this->resolveOpenPeriod($data['entry_date']);

            $entry->update([
                'fiscal_year_id' => $period->fiscal_year_id,
                'accounting_period_id' => $period->id,
                'entry_date' => $data['entry_date'],
                'reference_no' => $data['reference_no'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            $this->replaceLines($entry, $lines);

            return $entry->load(['lines.account.translations', 'lines.branch', 'lines.currency']);
        });
    }

    /**
     * الترحيل يقفل صف القيد ويعيد فحص حالته داخل المعاملة، فلا يُرحَّل القيد مرتين
     * ولو ضُغط الزر مرتين في نفس اللحظة.
     */
    public function post(JournalEntry $entry): JournalEntry
    {
        return DB::transaction(function () use ($entry) {
            $entry = JournalEntry::query()
                ->with(['accountingPeriod.fiscalYear', 'lines.account'])
                ->lockForUpdate()
                ->findOrFail($entry->getKey());

            if ($entry->status !== JournalEntryStatus::DRAFT) {
                throw new DomainException(__('Only draft journal entries can be posted.'));
            }

            if ($entry->accountingPeriod->isClosed() || $entry->accountingPeriod->fiscalYear?->status === AccountingPeriodStatus::CLOSED) {
                throw new DomainException(__('Cannot post to a closed accounting period.'));
            }

            $this->assertBalanced($entry);
            $this->assertLinesArePostable($entry);

            $entry->update([
                'status' => JournalEntryStatus::POSTED->value(),
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            $entry->lines()->with('account')->get()->each(fn($line) => $this->applyLineToBalance($entry, $line));

            return $entry->refresh()->load(['lines.account.translations', 'lines.branch', 'lines.currency']);
        });
    }

    /**
     * الالغاء بقيد عكسي لا بالحذف. القيد العكسي يحمل تاريخ القيد الاصلي ان كانت فترته
     * مفتوحة (فتبقى تقارير تلك الفترة صحيحة)، والا تاريخ اليوم (كما في ERPNext).
     */
    public function void(JournalEntry $entry, ?string $description = null, ?string $reversalDate = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $description, $reversalDate) {
            $entry = JournalEntry::query()
                ->with(['lines', 'accountingPeriod'])
                ->withCount('reversals')
                ->lockForUpdate()
                ->findOrFail($entry->getKey());

            if ($entry->status !== JournalEntryStatus::POSTED) {
                throw new DomainException(__('Only posted journal entries can be voided.'));
            }

            if ($entry->isReversal()) {
                throw new DomainException(__('Reversal journal entries cannot be voided.'));
            }

            if ($entry->hasReversal()) {
                throw new DomainException(__('This journal entry has already been voided by a reversal entry.'));
            }

            $reversal = $this->createDraft([
                'entry_date' => $reversalDate ?: $this->reversalDate($entry),
                'reference_no' => $entry->entry_number,
                'description' => $description ?: __('Reversal for') . ' ' . $entry->entry_number,
                'source_module' => 'Accounting',
                'source_type' => 'Reversal',
                'reversal_of_entry_id' => $entry->id,
            ], $entry->lines->map(fn($line) => [
                'account_id' => $line->account_id,
                'branch_id' => $line->branch_id,
                'currency_id' => $line->currency_id,
                'exchange_rate' => $line->exchange_rate,
                'debit' => $line->credit,
                'credit' => $line->debit,
                // المبلغ بالعملة الاساسية يُنقل كما هو (لا يُعاد حسابه) ليعكس القيد الاصلي تماماً.
                'debit_base' => $line->credit_base,
                'credit_base' => $line->debit_base,
                'description' => $description ?: __('Reversal for') . ' ' . $entry->entry_number,
            ])->toArray());

            $reversal->update(['reversal_of_entry_id' => $entry->id]);
            $this->post($reversal);

            $entry->update(['status' => JournalEntryStatus::VOIDED->value()]);

            return $entry->refresh();
        });
    }

    /**
     * الالغاء من شاشة القيود: للقيد اليدوي فقط. قيد المستند يُلغى من مستنده (canBeVoided).
     */
    public function voidManual(JournalEntry $entry): JournalEntry
    {
        if (! $entry->isManual()) {
            throw new DomainException(__('This journal entry belongs to a document. Cancel the document instead.'));
        }

        return $this->void($entry);
    }

    /**
     * حذف المسودة فقط؛ القيد المرحّل يُلغى بقيد عكسي ولا يُحذف.
     */
    public function delete(JournalEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->getKey());

            if ($entry->status !== JournalEntryStatus::DRAFT) {
                throw new DomainException(__('Only draft journal entries can be deleted.'));
            }

            $entry->delete();
        });
    }

    /**
     * رقم القيد من عدّاد مقفول (ReferenceNumberService) حسب شهر تاريخ القيد، فلا يتكرر
     * مع الطلبات المتزامنة. الارقام القديمة المحجوزة (قبل العدّاد) تُتخطّى.
     */
    public function nextEntryNumber(?string $date = null): string
    {
        $references = app(ReferenceNumberService::class);

        do {
            $number = $references->generate('journal_entry', $date ?: now()->toDateString(), ['prefix' => 'JE']);
        } while (JournalEntry::withTrashed()->where('entry_number', $number)->exists());

        return $number;
    }

    private function reversalDate(JournalEntry $entry): string
    {
        return $this->postableDate($entry->entry_date->toDateString());
    }

    /**
     * تاريخ قيد يتبع عملية سابقة (عكس، فرق عملة ...): تاريخها نفسه ان كانت فترته مفتوحة، والا
     * اليوم، فلا تتعطل العملية لأن شهرها أُقفل.
     */
    public function postableDate(string $date): string
    {
        $period = AccountingPeriod::query()->with('fiscalYear')->containsDate($date)->first();

        $isOpen = $period
            && $period->status !== AccountingPeriodStatus::CLOSED
            && $period->fiscalYear?->status !== AccountingPeriodStatus::CLOSED;

        return $isOpen ? $date : now()->toDateString();
    }

    public function assertBalanced(JournalEntry $entry): void
    {
        $entry->loadMissing('lines');

        $debit = round((float) $entry->lines->sum('debit_base'), 4);
        $credit = round((float) $entry->lines->sum('credit_base'), 4);

        if (round(abs($debit - $credit), 4) > 0.0001) {
            throw new DomainException(__('Journal entry debit and credit totals must match.'));
        }
    }

    private function replaceLines(JournalEntry $entry, array $lines): void
    {
        $entry->lines()->delete();

        $lines = $this->absorbBaseRoundingDifference(array_map(fn (array $line) => $this->normalizeLine($line), $lines));

        foreach ($lines as $line) {
            $entry->lines()->create($line);
        }

        $entry->refresh()->load('lines.account');
        $this->assertBalanced($entry);
        $this->assertLinesArePostable($entry);
    }

    private function normalizeLine(array $line): array
    {
        $exchangeRate = $this->decimalValue($line['exchange_rate'] ?? 1, 1, 8);
        $debit = $this->decimalValue($line['debit'] ?? 0);
        $credit = $this->decimalValue($line['credit'] ?? 0);
        $debitBase = $this->decimalValue($line['debit_base'] ?? null, round($debit * $exchangeRate, 4));
        $creditBase = $this->decimalValue($line['credit_base'] ?? null, round($credit * $exchangeRate, 4));

        return [
            'account_id' => (int) $line['account_id'],
            'branch_id' => filled($line['branch_id'] ?? null) ? (int) $line['branch_id'] : null,
            'currency_id' => (int) $line['currency_id'],
            'exchange_rate' => $exchangeRate,
            'debit' => $debit,
            'credit' => $credit,
            'debit_base' => $debitBase,
            'credit_base' => $creditBase,
            'description' => $line['description'] ?? null,
            'created_by' => $line['created_by'] ?? auth()->id(),
        ];
    }

    /**
     * القيد المتوازن بعملة المستند قد يختل بالعملة الاساسية بجزء من عشرة آلاف، لان كل سطر
     * يُقرَّب وحده بعد ضربه بسعر الصرف. يُحمَّل الفرق على اكبر سطر ليبقى القيد متوازناً.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function absorbBaseRoundingDifference(array $lines): array
    {
        $sameCurrency = count(array_unique(array_map(
            fn (array $line) => $line['currency_id'].'|'.$line['exchange_rate'],
            $lines
        ))) === 1;

        if (! $sameCurrency || round(array_sum(array_column($lines, 'debit')) - array_sum(array_column($lines, 'credit')), 4) != 0) {
            return $lines;
        }

        $difference = round(array_sum(array_column($lines, 'debit_base')) - array_sum(array_column($lines, 'credit_base')), 4);

        if ($difference == 0 || abs($difference) > count($lines) * 0.0001) {
            return $lines;
        }

        $largest = 0;

        foreach ($lines as $index => $line) {
            if (max($line['debit'], $line['credit']) > max($lines[$largest]['debit'], $lines[$largest]['credit'])) {
                $largest = $index;
            }
        }

        if ($lines[$largest]['debit'] > 0) {
            $lines[$largest]['debit_base'] = round($lines[$largest]['debit_base'] - $difference, 4);
        } else {
            $lines[$largest]['credit_base'] = round($lines[$largest]['credit_base'] + $difference, 4);
        }

        return $lines;
    }

    private function decimalValue(mixed $value, float $default = 0, int $scale = 4): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        if (! is_numeric($value)) {
            throw new DomainException(__('Invalid numeric value in journal line.'));
        }

        return round((float) $value, $scale);
    }

    private function assertLinesArePostable(JournalEntry $entry): void
    {
        $entry->loadMissing('lines.account');

        if ($entry->lines->count() < 2) {
            throw new DomainException(__('A journal entry must have at least two lines.'));
        }

        foreach ($entry->lines as $line) {
            if ((float) $line->debit > 0 && (float) $line->credit > 0) {
                throw new DomainException(__('A journal line cannot have both debit and credit values.'));
            }

            if ((float) $line->debit <= 0 && (float) $line->credit <= 0) {
                throw new DomainException(__('A journal line must have either debit or credit value.'));
            }

            if (! $line->account?->is_postable) {
                throw new DomainException(__('Journal lines can only use postable accounts.'));
            }

            // قيد العكس وقيد اقفال السنة ينقلان ارصدة قائمة فعلاً، فيُسمح لهما بالحساب الموقوف.
            if (! $line->account->is_active && ! $this->movesExistingBalances($entry)) {
                throw new DomainException(__('Journal lines cannot use inactive accounts.'));
            }
        }
    }

    /**
     * قيد عكس قيد آخر، او قيد اقفال سنة: لا يضيف حركة جديدة بل ينقل ارصدة موجودة.
     */
    private function movesExistingBalances(JournalEntry $entry): bool
    {
        return filled($entry->reversal_of_entry_id) || $entry->source_type === AccountingReportService::CLOSING_SOURCE_TYPE;
    }

    private function resolveOpenPeriod(string $date): AccountingPeriod
    {
        $period = AccountingPeriod::query()
            ->with('fiscalYear')
            ->containsDate($date)
            ->first();

        if (! $period) {
            throw new DomainException(__('No accounting period contains this date.'));
        }

        if ($period->status === AccountingPeriodStatus::CLOSED || $period->fiscalYear->status === AccountingPeriodStatus::CLOSED) {
            throw new DomainException(__('Cannot use a closed accounting period.'));
        }

        return $period;
    }

    /**
     * ملخص الارصدة (accounting_account_balances) نسخة مساعدة فقط؛ التقارير تُحسب من
     * سطور القيود. يُقفل الصف قبل التعديل حتى لا تضيع اضافة مع الترحيل المتزامن،
     * ويمكن اعادة بنائه بالكامل بالامر accounting:rebuild-balances.
     */
    private function applyLineToBalance(JournalEntry $entry, $line): void
    {
        $key = [
            'account_id' => $line->account_id,
            'branch_id' => $line->branch_id,
            'currency_id' => $line->currency_id,
            'fiscal_year_id' => $entry->fiscal_year_id,
            'accounting_period_id' => $entry->accounting_period_id,
        ];

        $balance = AccountBalance::query()->where($key)->lockForUpdate()->first()
            ?? AccountBalance::query()->create($key + [
                'debit' => 0,
                'credit' => 0,
                'debit_base' => 0,
                'credit_base' => 0,
                'balance_base' => 0,
                'created_by' => auth()->id(),
            ]);

        $balance->debit = round((float) $balance->debit + (float) $line->debit, 4);
        $balance->credit = round((float) $balance->credit + (float) $line->credit, 4);
        $balance->debit_base = round((float) $balance->debit_base + (float) $line->debit_base, 4);
        $balance->credit_base = round((float) $balance->credit_base + (float) $line->credit_base, 4);
        $balance->balance_base = round((float) $balance->debit_base - (float) $balance->credit_base, 4);
        $balance->save();
    }
}
