<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\PaymentAllocation;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\NormalBalance;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;

/**
 * التقارير المالية تُحسب دائماً من سطور القيود المرحّلة (لا من ملخص الارصدة):
 *
 * - القيد الملغى يُحسب مع قيده العكسي فيتصافيان، فتبقى تقارير الفترة الماضية كما كانت.
 * - حسابات الميزانية رصيدها تراكمي منذ البداية؛ حسابات قائمة الدخل تبدأ من اول السنة المالية.
 * - قيد اقفال السنة يُستبعد من قائمة الدخل (والا ظهرت السنة المقفلة صفراً)، ويُحسب في الميزانية.
 */
class AccountingReportService
{
    public const CLOSING_SOURCE_TYPE = 'PeriodClosingVoucher';

    private const EPSILON = 0.00005;

    /**
     * ميزان المراجعة: لكل حساب رصيد افتتاحي وحركة الفترة ورصيد ختامي (مدين/دائن صافٍ)،
     * مع تجميع الحسابات الأب من ابنائها.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function trialBalance(?string $from = null, ?string $to = null, bool $withGroups = false, bool $includeZero = false): Collection
    {
        $movement = $this->sums($from, $to);
        $opening = $from ? $this->openingSums($from) : collect();
        $accounts = Account::query()->with('translations')->orderBy('code')->get()->keyBy('id');

        $rows = $accounts->map(function (Account $account) use ($movement, $opening) {
            $period = $movement->get($account->id);
            $open = (float) ($opening->get($account->id) ?? 0);
            $debit = round((float) ($period->debit_base ?? 0), 4);
            $credit = round((float) ($period->credit_base ?? 0), 4);
            $closing = round($open + $debit - $credit, 4);

            return $this->row($account, [
                'opening_base' => round($open, 4),
                'debit_base' => $debit,
                'credit_base' => $credit,
                'balance_base' => $closing,
            ]);
        });

        if ($withGroups) {
            $rows = $this->rollUp($rows, $accounts);
        } else {
            $rows = $rows->filter(fn (array $row) => $row['is_postable'] || $this->hasValues($row));
        }

        return $rows
            ->filter(fn (array $row) => $includeZero || $this->hasValues($row))
            ->sortBy('code', SORT_NATURAL)
            ->values();
    }

    /**
     * مجاميع ميزان المراجعة من الحسابات الطرفية فقط (الحسابات الأب مجموع ابنائها فلا تُكرر):
     * الرصيد الافتتاحي والختامي مقسومان على جانبي المدين والدائن، والحركة كما هي.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  ناتج trialBalance()
     * @return array{opening_debit: float, opening_credit: float, debit: float, credit: float, closing_debit: float, closing_credit: float}
     */
    public function trialBalanceTotals(Collection $rows): array
    {
        $leaves = $rows->where('is_postable', true);
        $side = fn (string $key, int $sign) => round((float) $leaves->sum(fn (array $row) => max($row[$key] * $sign, 0)), 4);

        return [
            'opening_debit' => $side('opening_base', 1),
            'opening_credit' => $side('opening_base', -1),
            'debit' => round((float) $leaves->sum('debit_base'), 4),
            'credit' => round((float) $leaves->sum('credit_base'), 4),
            'closing_debit' => $side('balance_base', 1),
            'closing_credit' => $side('balance_base', -1),
        ];
    }

    /**
     * يتوازن الميزان حين يتساوى جانبا الرصيد الختامي.
     *
     * @param  array{closing_debit: float, closing_credit: float}  $totals  ناتج trialBalanceTotals()
     */
    public function trialBalanceIsBalanced(array $totals): bool
    {
        return abs($totals['closing_debit'] - $totals['closing_credit']) <= 0.0001;
    }

    /**
     * حسابات دفتر الاستاذ لحقل البحث (RemoteSelect): اول 30 حساباً بالرمز، او ما يطابق رمزه او اسمه.
     *
     * @return list<array{value: int, label: string}>
     */
    public function ledgerAccountOptions(string $search = '', int $limit = 30): array
    {
        $search = trim($search);

        return Account::query()
            ->with('translations')
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(fn (Builder $query) => $query
                    ->where('code', 'like', $like)
                    ->orWhereHas('translations', fn (Builder $query) => $query->where('name', 'like', $like)));
            })
            ->orderBy('code')
            ->limit($limit)
            ->get()
            ->map(fn (Account $account) => ['value' => (int) $account->id, 'label' => $this->accountLabel($account)])
            ->values()
            ->all();
    }

    /**
     * الحساب المختار في دفتر الاستاذ بنفس نص الخيار، او null ان لم يوجد.
     *
     * @return array{value: int, label: string}|null
     */
    public function ledgerAccountOption(?int $accountId): ?array
    {
        $account = $accountId ? Account::query()->with('translations')->find($accountId) : null;

        return $account ? ['value' => (int) $account->id, 'label' => $this->accountLabel($account)] : null;
    }

    /**
     * نص الحساب في القوائم بشكل Account::options(): مسافة بادئة بالمستوى ثم الرمز والاسم.
     */
    public function accountLabel(Account $account): string
    {
        return str_repeat('-- ', max((int) $account->level - 1, 0)).$account->code.' - '.$account->name;
    }

    /**
     * ارصدة حسابات نوع معين (للتقارير المختصرة وواجهة API).
     */
    public function accountTypeSummary(AccountType $type, ?string $from = null, ?string $to = null): Collection
    {
        return $this->trialBalance($from, $to)
            ->filter(fn (array $row) => $row['type'] === $type->value())
            ->values();
    }

    /**
     * قائمة الدخل للفترة (بدون قيد اقفال السنة). بلا تاريخ بداية تبدأ من اول السنة المالية.
     *
     * @return array{revenues: Collection, expenses: Collection, total_revenue: float, total_expense: float, net_profit: float, from: ?string, to: ?string}
     */
    public function incomeStatement(?string $from = null, ?string $to = null): array
    {
        $to = $to ?: now()->toDateString();
        $from = $from ?: $this->fiscalYearStart($to);
        $movement = $this->sums($from, $to, excludeClosing: true);

        $rows = fn (AccountType $type) => $this->accountsOfType($type)
            ->map(function (Account $account) use ($movement) {
                $sum = $movement->get($account->id);
                $balance = round((float) ($sum->debit_base ?? 0) - (float) ($sum->credit_base ?? 0), 4);

                return $this->row($account, ['balance_base' => $balance, 'amount' => $account->type === AccountType::REVENUE ? -$balance : $balance]);
            })
            ->filter(fn (array $row) => abs($row['balance_base']) > self::EPSILON)
            ->values();

        $revenues = $rows(AccountType::REVENUE);
        $expenses = $rows(AccountType::EXPENSE);
        $totalRevenue = round((float) $revenues->sum('amount'), 4);
        $totalExpense = round((float) $expenses->sum('amount'), 4);

        return [
            'revenues' => $revenues,
            'expenses' => $expenses,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_profit' => round($totalRevenue - $totalExpense, 4),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * الميزانية العمومية بتاريخ معين. الربح/الخسارة الذي لم يُقفل بعد الى الارباح المحتجزة
     * يظهر سطراً ضمن حقوق الملكية، فتتوازن الميزانية دائماً: الاصول = الالتزامات + حقوق الملكية.
     *
     * @return array<string, mixed>
     */
    public function balanceSheet(?string $to = null): array
    {
        $to = $to ?: now()->toDateString();
        $cumulative = $this->sums(null, $to);

        $section = fn (AccountType $type, int $sign) => $this->accountsOfType($type)
            ->map(function (Account $account) use ($cumulative, $sign) {
                $sum = $cumulative->get($account->id);
                $balance = round((float) ($sum->debit_base ?? 0) - (float) ($sum->credit_base ?? 0), 4);

                return $this->row($account, ['balance_base' => $balance, 'amount' => round($balance * $sign, 4)]);
            })
            ->filter(fn (array $row) => abs($row['balance_base']) > self::EPSILON)
            ->values();

        $assets = $section(AccountType::ASSET, 1);
        $liabilities = $section(AccountType::LIABILITY, -1);
        $equity = $section(AccountType::EQUITY, -1);

        $unclosedProfit = 0.0;

        foreach ([AccountType::REVENUE, AccountType::EXPENSE] as $type) {
            foreach ($this->accountsOfType($type) as $account) {
                $sum = $cumulative->get($account->id);
                $unclosedProfit -= (float) ($sum->debit_base ?? 0) - (float) ($sum->credit_base ?? 0);
            }
        }

        $unclosedProfit = round($unclosedProfit, 4);
        $totalAssets = round((float) $assets->sum('amount'), 4);
        $totalLiabilities = round((float) $liabilities->sum('amount'), 4);
        $totalEquity = round((float) $equity->sum('amount') + $unclosedProfit, 4);

        return [
            'to' => $to,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'unclosed_profit' => $unclosedProfit,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'difference' => round($totalAssets - $totalLiabilities - $totalEquity, 4),
        ];
    }

    /**
     * دفتر الاستاذ لحساب (او مجموعة وكل ابنائها): رصيد افتتاحي ثم الحركات برصيد متراكم.
     *
     * @return array{opening: float, lines: LengthAwarePaginator, closing: float, debit: float, credit: float}
     */
    public function generalLedger(?int $accountId, ?string $from, ?string $to, string $search = '', int $perPage = 50, int $page = 1): array
    {
        $accountIds = $accountId ? $this->accountWithDescendants($accountId) : null;
        $lineTable = (new JournalLine)->getTable();

        $base = $this->postedLines()
            ->when($accountIds, fn (Builder $query) => $query->whereIn("{$lineTable}.account_id", $accountIds))
            ->when($from, fn (Builder $query) => $query->where('posted_entries.entry_date', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('posted_entries.entry_date', '<=', $to))
            ->when(trim($search) !== '', function (Builder $query) use ($lineTable, $search) {
                $like = '%'.trim($search).'%';

                $query->where(fn (Builder $query) => $query
                    ->where('posted_entries.entry_number', 'like', $like)
                    ->orWhere('posted_entries.reference_no', 'like', $like)
                    ->orWhere('posted_entries.description', 'like', $like)
                    ->orWhere("{$lineTable}.description", 'like', $like));
            });

        $opening = 0.0;

        if ($accountIds && $from) {
            $opening = round((float) $this->postedLines()
                ->whereIn("{$lineTable}.account_id", $accountIds)
                ->where('posted_entries.entry_date', '<', $from)
                ->when($this->isProfitAndLoss($accountId), fn (Builder $query) => $query->where('posted_entries.entry_date', '>=', $this->fiscalYearStart($from)))
                ->sum(DB::raw("{$lineTable}.debit_base - {$lineTable}.credit_base")), 4);
        }

        $totals = (clone $base)->selectRaw("COALESCE(SUM({$lineTable}.debit_base), 0) as debit, COALESCE(SUM({$lineTable}.credit_base), 0) as credit")->first();

        $lines = (clone $base)
            ->with(['account.translations', 'journalEntry'])
            ->select("{$lineTable}.*", 'posted_entries.entry_date as ledger_date')
            ->orderBy('posted_entries.entry_date')
            ->orderBy('posted_entries.id')
            ->orderBy("{$lineTable}.id")
            ->paginate($perPage, ['*'], 'page', $page);

        // الرصيد المتراكم في اول الصفحة = الافتتاحي + ما قبلها من الصفحات.
        $before = 0.0;

        if ($lines->currentPage() > 1) {
            $previous = (clone $base)
                ->select(DB::raw("{$lineTable}.debit_base - {$lineTable}.credit_base as net"))
                ->orderBy('posted_entries.entry_date')
                ->orderBy('posted_entries.id')
                ->orderBy("{$lineTable}.id")
                ->limit(($lines->currentPage() - 1) * $perPage)
                ->pluck('net');
            $before = (float) $previous->sum();
        }

        $running = round($opening + $before, 4);
        $lines->getCollection()->transform(function (JournalLine $line) use (&$running) {
            $running = round($running + (float) $line->debit_base - (float) $line->credit_base, 4);
            $line->setAttribute('running_balance', $running);

            return $line;
        });

        return [
            'opening' => $opening,
            'lines' => $lines,
            'debit' => round((float) $totals->debit, 4),
            'credit' => round((float) $totals->credit, 4),
            'closing' => round($opening + (float) $totals->debit - (float) $totals->credit, 4),
        ];
    }

    /**
     * اعمار الديون (مدينة او دائنة) بتاريخ معين من الفواتير المرحّلة ومتبقيها في ذلك التاريخ،
     * مقسمة على فترات استحقاق. المبالغ بالعملة الاساسية (سعر صرف الفاتورة).
     *
     * @return array{rows: Collection, totals: array<string, float>, buckets: array<string, string>}
     */
    public function aging(string $kind, ?string $asOf = null): array
    {
        $asOf = $asOf ?: now()->toDateString();
        $isReceivable = $kind === 'receivable';
        $invoiceClass = $isReceivable ? SalesInvoice::class : PurchaseInvoice::class;
        $postedStatus = $isReceivable ? SalesInvoiceStatus::POSTED->value : PurchaseInvoiceStatus::POSTED->value;

        $invoices = $invoiceClass::query()
            ->with('client.media')
            ->where('status', $postedStatus)
            ->where('invoice_date', '<=', $asOf)
            ->get();

        $paid = PaymentAllocation::query()
            ->where('invoice_type', (new $invoiceClass)->getMorphClass())
            ->whereIn('invoice_id', $invoices->modelKeys())
            ->where('allocation_date', '<=', $asOf)
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount) as paid')
            ->pluck('paid', 'invoice_id');

        $buckets = [
            'current' => __('Not Due'),
            'd30' => __('1-30 Days'),
            'd60' => __('31-60 Days'),
            'd90' => __('61-90 Days'),
            'older' => __('Over 90 Days'),
        ];
        $asOfDate = Carbon::parse($asOf);
        $rows = [];

        foreach ($invoices as $invoice) {
            $outstanding = round((float) $invoice->total_amount - (float) ($paid[$invoice->id] ?? 0), 4);

            if ($outstanding <= self::EPSILON) {
                continue;
            }

            $base = round($outstanding * ((float) $invoice->exchange_rate ?: 1), 4);
            $due = Carbon::parse($invoice->due_date ?? $invoice->invoice_date);
            $days = $due->lt($asOfDate) ? (int) $due->diffInDays($asOfDate) : 0;
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => 'd30',
                $days <= 60 => 'd60',
                $days <= 90 => 'd90',
                default => 'older',
            };

            $clientId = (int) $invoice->client_id;
            $rows[$clientId] ??= ['client' => $invoice->client, 'invoices' => 0, 'total' => 0.0] + array_fill_keys(array_keys($buckets), 0.0);
            $rows[$clientId][$bucket] = round($rows[$clientId][$bucket] + $base, 4);
            $rows[$clientId]['total'] = round($rows[$clientId]['total'] + $base, 4);
            $rows[$clientId]['invoices']++;
        }

        $rows = collect($rows)->sortByDesc('total')->values();
        $totals = ['total' => round((float) $rows->sum('total'), 4)];

        foreach (array_keys($buckets) as $key) {
            $totals[$key] = round((float) $rows->sum($key), 4);
        }

        return ['rows' => $rows, 'totals' => $totals, 'buckets' => $buckets];
    }

    /**
     * بداية السنة المالية التي تحتوي التاريخ (او 1 يناير ان لم توجد سنة مالية).
     */
    public function fiscalYearStart(string $date): string
    {
        $year = FiscalYear::query()
            ->where('starts_at', '<=', $date)
            ->where('ends_at', '>=', $date)
            ->orderByDesc('starts_at')
            ->first();

        return $year?->starts_at?->toDateString() ?? Carbon::parse($date)->startOfYear()->toDateString();
    }

    /**
     * مجاميع المدين والدائن لكل حساب بين تاريخين.
     *
     * @return Collection<int, object{account_id:int, debit_base:float, credit_base:float}>
     */
    private function sums(?string $from, ?string $to, bool $excludeClosing = false): Collection
    {
        $lineTable = (new JournalLine)->getTable();

        return $this->postedLines()
            ->toBase()
            ->selectRaw("{$lineTable}.account_id, SUM({$lineTable}.debit_base) as debit_base, SUM({$lineTable}.credit_base) as credit_base")
            ->when($from, fn ($query) => $query->where('posted_entries.entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->where('posted_entries.entry_date', '<=', $to))
            ->when($excludeClosing, fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('posted_entries.source_type')
                ->orWhere('posted_entries.source_type', '!=', self::CLOSING_SOURCE_TYPE)))
            ->groupBy("{$lineTable}.account_id")
            ->get()
            ->keyBy('account_id');
    }

    /**
     * الرصيد الافتتاحي قبل تاريخ: تراكمي لحسابات الميزانية، ومن اول السنة المالية لحسابات
     * قائمة الدخل (ارصدتها السابقة نُقلت للارباح المحتجزة بقيد الاقفال).
     *
     * @return Collection<int, float>
     */
    private function openingSums(string $from): Collection
    {
        $before = Carbon::parse($from)->subDay()->toDateString();
        $all = $this->sums(null, $before);
        $yearStart = $this->fiscalYearStart($from);
        $sinceYearStart = $yearStart < $from ? $this->sums($yearStart, $before) : collect();
        $profitAndLoss = Account::query()->whereIn('type', [AccountType::REVENUE->value(), AccountType::EXPENSE->value()])->pluck('id')->flip();

        return $all->keys()->merge($sinceYearStart->keys())->unique()->mapWithKeys(function ($accountId) use ($all, $sinceYearStart, $profitAndLoss) {
            $source = $profitAndLoss->has($accountId) ? $sinceYearStart : $all;
            $sum = $source->get($accountId);

            return [$accountId => round((float) ($sum->debit_base ?? 0) - (float) ($sum->credit_base ?? 0), 4)];
        });
    }

    private function postedLines(): Builder
    {
        $lineTable = (new JournalLine)->getTable();
        $entryTable = (new JournalEntry)->getTable();

        return JournalLine::query()
            ->join("{$entryTable} as posted_entries", 'posted_entries.id', '=', "{$lineTable}.journal_entry_id")
            ->whereNull('posted_entries.deleted_at')
            ->whereNull("{$lineTable}.deleted_at")
            ->whereIn('posted_entries.status', [
                JournalEntryStatus::POSTED->value(),
                JournalEntryStatus::VOIDED->value(),
            ]);
    }

    /**
     * @param  array<string, float>  $values
     * @return array<string, mixed>
     */
    private function row(Account $account, array $values): array
    {
        $balance = (float) ($values['balance_base'] ?? 0);

        return [
            'account_id' => $account->id,
            'parent_id' => $account->parent_id,
            'code' => $account->code,
            'name' => $account->name,
            'level' => (int) $account->level,
            'type' => $account->type?->value(),
            'is_postable' => (bool) $account->is_postable,
            'opening_base' => 0.0,
            'debit_base' => 0.0,
            'credit_base' => 0.0,
            ...$values,
            'normal_balance_amount' => $account->normal_balance === NormalBalance::CREDIT ? -$balance : $balance,
        ];
    }

    /**
     * يضيف لكل حساب أب مجموع ابنائه (افتتاحي، حركة، ختامي).
     */
    private function rollUp(Collection $rows, Collection $accounts): Collection
    {
        $rows = $rows->keyBy('account_id');
        $byLevel = $accounts->sortByDesc('level');

        foreach ($byLevel as $account) {
            if (! $account->parent_id || ! $rows->has($account->parent_id)) {
                continue;
            }

            $child = $rows->get($account->id);
            $parent = $rows->get($account->parent_id);

            foreach (['opening_base', 'debit_base', 'credit_base', 'balance_base', 'normal_balance_amount'] as $key) {
                $parent[$key] = round($parent[$key] + $child[$key], 4);
            }

            $rows->put($account->parent_id, $parent);
        }

        return $rows->values();
    }

    private function hasValues(array $row): bool
    {
        return abs($row['opening_base']) > self::EPSILON
            || abs($row['debit_base']) > self::EPSILON
            || abs($row['credit_base']) > self::EPSILON
            || abs($row['balance_base']) > self::EPSILON;
    }

    /**
     * @return Collection<int, Account>
     */
    private function accountsOfType(AccountType $type): Collection
    {
        return Account::query()->with('translations')->where('type', $type->value())->postable()->orderBy('code')->get();
    }

    /**
     * @return array<int, int>
     */
    private function accountWithDescendants(int $accountId): array
    {
        $account = Account::query()->find($accountId);

        if (! $account) {
            return [$accountId];
        }

        return Account::query()->where('path', 'like', $account->path.'%')->pluck('id')->push($account->id)->unique()->values()->all();
    }

    private function isProfitAndLoss(int $accountId): bool
    {
        $type = Account::query()->whereKey($accountId)->value('type');

        return in_array((int) ($type instanceof AccountType ? $type->value() : $type), [AccountType::REVENUE->value(), AccountType::EXPENSE->value()], true);
    }
}
