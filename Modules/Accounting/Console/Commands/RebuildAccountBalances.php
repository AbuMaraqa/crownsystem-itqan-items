<?php

namespace Modules\Accounting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\AccountBalance;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Enums\JournalEntryStatus;

/**
 * يعيد بناء ملخص الارصدة (accounting_account_balances) من سطور القيود المرحّلة.
 * الملخص نسخة مساعدة فقط؛ مصدر الحقيقة هو سطور القيود، فهذا الامر يصلح اي انحراف.
 */
class RebuildAccountBalances extends Command
{
    protected $signature = 'accounting:rebuild-balances';

    protected $description = 'Rebuild the account balances summary from posted journal lines';

    public function handle(): int
    {
        $lineTable = (new JournalLine)->getTable();
        $entryTable = (new JournalEntry)->getTable();
        $balanceTable = (new AccountBalance)->getTable();

        $count = DB::transaction(function () use ($lineTable, $entryTable, $balanceTable) {
            DB::table($balanceTable)->delete();

            $rows = DB::table($lineTable)
                ->join("{$entryTable} as entries", 'entries.id', '=', "{$lineTable}.journal_entry_id")
                ->whereNull('entries.deleted_at')
                ->whereNull("{$lineTable}.deleted_at")
                ->whereIn('entries.status', [JournalEntryStatus::POSTED->value(), JournalEntryStatus::VOIDED->value()])
                ->groupBy("{$lineTable}.account_id", "{$lineTable}.branch_id", "{$lineTable}.currency_id", 'entries.fiscal_year_id', 'entries.accounting_period_id')
                ->selectRaw("{$lineTable}.account_id, {$lineTable}.branch_id, {$lineTable}.currency_id, entries.fiscal_year_id, entries.accounting_period_id,
                    SUM({$lineTable}.debit) as debit, SUM({$lineTable}.credit) as credit,
                    SUM({$lineTable}.debit_base) as debit_base, SUM({$lineTable}.credit_base) as credit_base")
                ->get();

            $now = now();

            foreach ($rows->chunk(500) as $chunk) {
                DB::table($balanceTable)->insert($chunk->map(fn ($row) => [
                    'account_id' => $row->account_id,
                    'branch_id' => $row->branch_id,
                    'currency_id' => $row->currency_id,
                    'fiscal_year_id' => $row->fiscal_year_id,
                    'accounting_period_id' => $row->accounting_period_id,
                    'debit' => round((float) $row->debit, 4),
                    'credit' => round((float) $row->credit, 4),
                    'debit_base' => round((float) $row->debit_base, 4),
                    'credit_base' => round((float) $row->credit_base, 4),
                    'balance_base' => round((float) $row->debit_base - (float) $row->credit_base, 4),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            return $rows->count();
        });

        $this->info("Account balances rebuilt ({$count} rows).");

        return self::SUCCESS;
    }
}
