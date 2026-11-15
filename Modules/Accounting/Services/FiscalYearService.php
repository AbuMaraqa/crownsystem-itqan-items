<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\JournalEntryStatus;

/**
 * السنوات المالية وفتراتها الشهرية:
 *
 * - لا تتداخل سنتان، والفترات تُولَّد شهرياً من تواريخ السنة.
 * - اقفال شهر يمنع الترحيل فيه (تجميد الحسابات حتى تاريخ)، ويُرفض ان بقيت فيه مسودات.
 * - اقفال السنة: يُرحَّل قيد الاقفال (الارباح المحتجزة) تلقائياً ثم تُقفل كل الفترات.
 */
class FiscalYearService
{
    public function create(array $data): FiscalYear
    {
        return DB::transaction(function () use ($data) {
            $this->assertNoOverlap($data['starts_at'], $data['ends_at']);

            if ($data['is_default'] ?? false) {
                FiscalYear::query()->update(['is_default' => false]);
            }

            $year = FiscalYear::create([
                'name' => $data['name'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'status' => AccountingPeriodStatus::OPEN->value(),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'created_by' => auth()->id(),
            ]);

            $this->generatePeriods($year);

            return $year->refresh();
        });
    }

    public function update(FiscalYear $year, array $data): FiscalYear
    {
        return DB::transaction(function () use ($year, $data) {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->getKey());
            $datesChanged = Carbon::parse($data['starts_at'])->toDateString() !== $year->starts_at->toDateString()
                || Carbon::parse($data['ends_at'])->toDateString() !== $year->ends_at->toDateString();

            if ($datesChanged) {
                if (JournalEntry::withTrashed()->where('fiscal_year_id', $year->id)->exists()) {
                    throw new DomainException(__('The dates of a fiscal year that has journal entries cannot be changed.'));
                }

                $this->assertNoOverlap($data['starts_at'], $data['ends_at'], $year->id);
            }

            if ($data['is_default'] ?? false) {
                FiscalYear::query()->whereKeyNot($year->id)->update(['is_default' => false]);
            }

            $year->update([
                'name' => $data['name'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);

            if ($datesChanged) {
                $year->periods()->forceDelete();
                $this->generatePeriods($year->refresh());
            }

            return $year->refresh();
        });
    }

    /**
     * اقفال السنة: قيد اقفال ينقل صافي الربح للارباح المحتجزة، ثم اقفال كل الفترات.
     */
    public function close(FiscalYear $year): FiscalYear
    {
        return DB::transaction(function () use ($year) {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->getKey());

            if ($year->status === AccountingPeriodStatus::CLOSED) {
                throw new DomainException(__('This fiscal year is already closed.'));
            }

            $this->assertNoDrafts($year->starts_at->toDateString(), $year->ends_at->toDateString());

            // قيد الاقفال يُرحَّل في آخر فترة، فتُفتح مؤقتاً ان كانت مقفلة.
            $lastPeriod = AccountingPeriod::query()->where('fiscal_year_id', $year->id)->containsDate($year->ends_at->toDateString())->first();
            $lastPeriod?->update(['status' => AccountingPeriodStatus::OPEN->value()]);

            app(PeriodClosingVoucherService::class)->close($year);

            $year->periods()->update(['status' => AccountingPeriodStatus::CLOSED->value()]);
            $year->update(['status' => AccountingPeriodStatus::CLOSED->value()]);

            return $year->refresh();
        });
    }

    public function reopen(FiscalYear $year): FiscalYear
    {
        return DB::transaction(function () use ($year) {
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->getKey());
            $year->periods()->update(['status' => AccountingPeriodStatus::OPEN->value()]);
            $year->update(['status' => AccountingPeriodStatus::OPEN->value()]);

            return $year->refresh();
        });
    }

    public function closePeriod(AccountingPeriod $period): AccountingPeriod
    {
        return DB::transaction(function () use ($period) {
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->getKey());
            $this->assertNoDrafts($period->starts_at->toDateString(), $period->ends_at->toDateString());
            $period->update(['status' => AccountingPeriodStatus::CLOSED->value()]);

            return $period->refresh();
        });
    }

    public function reopenPeriod(AccountingPeriod $period): AccountingPeriod
    {
        return DB::transaction(function () use ($period) {
            $period = AccountingPeriod::query()->with('fiscalYear')->lockForUpdate()->findOrFail($period->getKey());

            if ($period->fiscalYear?->status === AccountingPeriodStatus::CLOSED) {
                throw new DomainException(__('Reopen the fiscal year first.'));
            }

            $period->update(['status' => AccountingPeriodStatus::OPEN->value()]);

            return $period->refresh();
        });
    }

    private function generatePeriods(FiscalYear $year): void
    {
        $start = $year->starts_at->copy();
        $finish = $year->ends_at->copy();
        $cursor = $start->copy()->startOfMonth();

        while ($cursor->lte($finish)) {
            AccountingPeriod::create([
                'fiscal_year_id' => $year->id,
                'name' => $cursor->format('F Y'),
                'starts_at' => $cursor->copy()->max($start)->toDateString(),
                'ends_at' => $cursor->copy()->endOfMonth()->min($finish)->toDateString(),
                'status' => AccountingPeriodStatus::OPEN->value(),
                'created_by' => auth()->id(),
            ]);

            $cursor->addMonth();
        }
    }

    private function assertNoOverlap(string $startsAt, string $endsAt, ?int $ignoreId = null): void
    {
        if (Carbon::parse($endsAt)->lt(Carbon::parse($startsAt))) {
            throw new DomainException(__('The end date must be after the start date.'));
        }

        $overlaps = FiscalYear::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('starts_at', '<=', $endsAt)
            ->where('ends_at', '>=', $startsAt)
            ->exists();

        if ($overlaps) {
            throw new DomainException(__('This fiscal year overlaps another fiscal year.'));
        }
    }

    private function assertNoDrafts(string $from, string $to): void
    {
        $drafts = JournalEntry::query()
            ->where('status', JournalEntryStatus::DRAFT->value())
            ->whereBetween('entry_date', [$from, $to])
            ->count();

        if ($drafts > 0) {
            throw new DomainException(__('There are :count draft journal entries in this period. Post or delete them first.', ['count' => $drafts]));
        }
    }
}
