<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Http\Requests\Admin\ReportRequest;
use Modules\Accounting\Services\AccountingReportService;
use Modules\Accounting\Support\Number;
use Modules\Core\Services\ExcelService;
use Modules\Core\Support\UserCellData;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * التقارير المالية: ميزان المراجعة، دفتر الاستاذ، الميزانية العمومية، قائمة الدخل، اعمار الديون.
 * كل الارقام من AccountingReportService؛ هنا تُنسَّق للعرض فقط. الفلاتر في رابط الصفحة،
 * وتصدير Excel يقرأ نفس الفلاتر.
 */
class ReportController extends Controller
{
    private const LEDGER_PER_PAGE = 50;

    /** حد سطور تصدير دفتر الاستاذ في ملف واحد. */
    private const LEDGER_EXPORT_LIMIT = 50000;

    public function __construct(
        private readonly AccountingReportService $reports,
        private readonly ExcelService $excel,
    ) {}

    // ------------------------------------------------------------ trial balance

    public function trialBalance(ReportRequest $request): Response
    {
        $filters = $this->trialBalanceFilters($request);
        $rows = $this->reports->trialBalance($filters['from'], $filters['to'], $filters['withGroups'], $filters['includeZero']);
        $totals = $this->reports->trialBalanceTotals($rows);

        return Inertia::render('Accounting/Report/TrialBalance', [
            'filters' => $filters,
            'rows' => $rows->map(fn (array $row) => [
                'account_id' => (int) $row['account_id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'indent' => $filters['withGroups'] ? max((int) $row['level'] - 1, 0) : 0,
                'is_postable' => (bool) $row['is_postable'],
                'opening_debit' => $this->side($row['opening_base'], 1),
                'opening_credit' => $this->side($row['opening_base'], -1),
                'debit' => $this->side($row['debit_base'], 1),
                'credit' => $this->side($row['credit_base'], 1),
                'closing_debit' => $this->side($row['balance_base'], 1),
                'closing_credit' => $this->side($row['balance_base'], -1),
            ])->values(),
            'totals' => array_map(fn (float $value) => Number::money($value), $totals),
            'balanced' => $this->reports->trialBalanceIsBalanced($totals),
        ]);
    }

    public function trialBalanceExport(ReportRequest $request): BinaryFileResponse
    {
        $filters = $this->trialBalanceFilters($request);
        $rows = $this->reports->trialBalance($filters['from'], $filters['to'], $filters['withGroups'], $filters['includeZero']);
        $totals = $this->reports->trialBalanceTotals($rows);
        $amount = fn (float $value, int $sign) => $value * $sign > 0 ? round($value * $sign, 4) : null;

        $lines = $rows->map(fn (array $row) => [
            $row['code'],
            $row['name'],
            $amount($row['opening_base'], 1),
            $amount($row['opening_base'], -1),
            $amount($row['debit_base'], 1),
            $amount($row['credit_base'], 1),
            $amount($row['balance_base'], 1),
            $amount($row['balance_base'], -1),
        ])->push(['', __('Total'), ...array_values($totals)]);

        return $this->excel->downloadRows($lines, $this->filename('trial-balance', $filters['to']), [
            __('Code'), __('Account'),
            __('Opening Balance').' - '.__('Debit'), __('Opening Balance').' - '.__('Credit'),
            __('Period Movement').' - '.__('Debit'), __('Period Movement').' - '.__('Credit'),
            __('Closing Balance').' - '.__('Debit'), __('Closing Balance').' - '.__('Credit'),
        ]);
    }

    // ------------------------------------------------------------ general ledger

    public function ledger(ReportRequest $request): Response
    {
        $filters = $this->ledgerFilters($request);
        $perPage = $request->idFilter('per_page') ?? self::LEDGER_PER_PAGE;
        $ledger = $this->reports->generalLedger($filters['account_id'], $filters['from'], $filters['to'], $filters['search'], $perPage, $request->idFilter('page') ?? 1);
        $lines = $ledger['lines'];

        return Inertia::render('Accounting/Report/GeneralLedger', [
            'filters' => $filters,
            'account' => $this->reports->ledgerAccountOption($filters['account_id']),
            'showBalance' => $filters['account_id'] !== null,
            'showOpening' => $filters['account_id'] !== null && $filters['from'] !== null,
            'ledger' => [
                'opening' => $this->signed($ledger['opening']),
                'debit' => Number::money($ledger['debit']),
                'credit' => Number::money($ledger['credit']),
                'closing' => $this->signed($ledger['closing']),
            ],
            'lines' => collect($lines->items())->map(fn (JournalLine $line) => [
                'id' => (int) $line->id,
                'date' => $line->journalEntry?->entry_date?->format('d/m/Y'),
                'entry_id' => (int) $line->journal_entry_id,
                'entry_number' => $line->journalEntry?->entry_number,
                'reference_no' => $line->journalEntry?->reference_no,
                'account' => trim($line->account?->code.' - '.$line->account?->name, ' -'),
                'description' => $line->description ?: $line->journalEntry?->description,
                'debit' => (float) $line->debit_base > 0 ? Number::money($line->debit_base) : '',
                'credit' => (float) $line->credit_base > 0 ? Number::money($line->credit_base) : '',
                'balance' => $this->signed((float) $line->running_balance),
            ])->values(),
            'meta' => [
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'per_page' => $lines->perPage(),
                'total' => $lines->total(),
                'from' => $lines->firstItem(),
                'to' => $lines->lastItem(),
            ],
        ]);
    }

    public function ledgerExport(ReportRequest $request): BinaryFileResponse
    {
        $filters = $this->ledgerFilters($request);
        $ledger = $this->reports->generalLedger($filters['account_id'], $filters['from'], $filters['to'], $filters['search'], self::LEDGER_EXPORT_LIMIT, 1);
        $withBalance = $filters['account_id'] !== null;
        $amount = fn (mixed $value) => (float) $value > 0 ? round((float) $value, 4) : null;

        $lines = collect($ledger['lines']->items())->map(fn (JournalLine $line) => array_merge([
            $line->journalEntry?->entry_date?->format('d/m/Y'),
            $line->journalEntry?->entry_number,
            $line->journalEntry?->reference_no,
            trim($line->account?->code.' - '.$line->account?->name, ' -'),
            $line->description ?: $line->journalEntry?->description,
            $amount($line->debit_base),
            $amount($line->credit_base),
        ], $withBalance ? [(float) $line->running_balance] : []));

        if ($withBalance && $filters['from'] !== null) {
            $lines->prepend(['', '', '', '', __('Opening Balance'), null, null, $ledger['opening']]);
        }

        $lines->push(array_merge(['', '', '', '', __('Total'), $ledger['debit'], $ledger['credit']], $withBalance ? [$ledger['closing']] : []));

        return $this->excel->downloadRows($lines, $this->filename('ledger', $filters['to']), array_merge(
            [__('Date'), __('Entry Number'), __('Reference'), __('Account'), __('Description'), __('Debit'), __('Credit')],
            $withBalance ? [__('Balance')] : [],
        ));
    }

    /**
     * بحث الحسابات لحقل الحساب في دفتر الاستاذ.
     */
    public function ledgerAccounts(Request $request): JsonResponse
    {
        return response()->json($this->reports->ledgerAccountOptions($request->string('q')->toString()));
    }

    // ------------------------------------------------------------ balance sheet

    public function balanceSheet(ReportRequest $request): Response
    {
        $report = $this->reports->balanceSheet($request->dateFilter('to'));
        $profit = abs($report['unclosed_profit']) > 0.00005
            ? [['label' => __('Profit / loss not yet closed to retained earnings'), 'amount' => Number::money($report['unclosed_profit'])]]
            : [];

        return Inertia::render('Accounting/Report/BalanceSheet', [
            'filters' => ['to' => $report['to']],
            'sections' => [
                $this->section(__('Assets'), $report['assets'], $report['total_assets']),
                $this->section(__('Liabilities'), $report['liabilities'], $report['total_liabilities']),
                $this->section(__('Equity'), $report['equity'], $report['total_equity'], $profit),
            ],
            'totals' => [
                'assets' => Number::money($report['total_assets']),
                'liabilities_and_equity' => Number::money(round($report['total_liabilities'] + $report['total_equity'], 4)),
                'difference' => Number::money($report['difference']),
            ],
            'balanced' => abs($report['difference']) <= 0.0001,
        ]);
    }

    public function balanceSheetExport(ReportRequest $request): BinaryFileResponse
    {
        $report = $this->reports->balanceSheet($request->dateFilter('to'));
        $lines = collect();

        foreach ([[__('Assets'), 'assets', 'total_assets'], [__('Liabilities'), 'liabilities', 'total_liabilities'], [__('Equity'), 'equity', 'total_equity']] as [$title, $key, $total]) {
            $lines->push([$title, '', null]);
            $report[$key]->each(fn (array $row) => $lines->push([$row['code'], $row['name'], $row['amount']]));

            if ($key === 'equity' && abs($report['unclosed_profit']) > 0.00005) {
                $lines->push(['', __('Profit / loss not yet closed to retained earnings'), $report['unclosed_profit']]);
            }

            $lines->push(['', __('Total').' '.$title, $report[$total]]);
        }

        $lines->push(['', __('Total Liabilities and Equity'), round($report['total_liabilities'] + $report['total_equity'], 4)]);
        $lines->push(['', __('Difference'), $report['difference']]);

        return $this->excel->downloadRows($lines, $this->filename('balance-sheet', $report['to']), [__('Code'), __('Account'), __('Balance')]);
    }

    // ------------------------------------------------------------ income statement

    public function incomeStatement(ReportRequest $request): Response
    {
        $report = $this->incomeStatementReport($request);

        return Inertia::render('Accounting/Report/IncomeStatement', [
            'filters' => ['from' => $report['from'], 'to' => $report['to']],
            'sections' => [
                $this->section(__('Revenue'), $report['revenues'], $report['total_revenue']),
                $this->section(__('Expenses'), $report['expenses'], $report['total_expense']),
            ],
            'net' => [
                'label' => $report['net_profit'] >= 0 ? __('Net Profit') : __('Net Loss'),
                'amount' => Number::money(abs($report['net_profit'])),
                'profit' => $report['net_profit'] >= 0,
            ],
        ]);
    }

    public function incomeStatementExport(ReportRequest $request): BinaryFileResponse
    {
        $report = $this->incomeStatementReport($request);
        $lines = collect();

        foreach ([[__('Revenue'), 'revenues', 'total_revenue'], [__('Expenses'), 'expenses', 'total_expense']] as [$title, $key, $total]) {
            $lines->push([$title, '', null]);
            $report[$key]->each(fn (array $row) => $lines->push([$row['code'], $row['name'], $row['amount']]));
            $lines->push(['', __('Total').' '.$title, $report[$total]]);
        }

        $lines->push(['', $report['net_profit'] >= 0 ? __('Net Profit') : __('Net Loss'), abs($report['net_profit'])]);

        return $this->excel->downloadRows($lines, $this->filename('income-statement', $report['to']), [__('Code'), __('Account'), __('Balance')]);
    }

    // ------------------------------------------------------------ aging

    public function aging(ReportRequest $request, string $kind = 'receivable'): Response
    {
        $kind = $kind === 'payable' ? 'payable' : 'receivable';
        $asOf = $request->dateFilter('asOf') ?? now()->toDateString();
        $report = $this->reports->aging($kind, $asOf);
        $money = fn (float $value) => $value > 0 ? Number::money($value) : '';

        return Inertia::render('Accounting/Report/Aging', [
            'kind' => $kind,
            'title' => $kind === 'payable' ? __('Payables Aging') : __('Receivables Aging'),
            'partyLabel' => $kind === 'payable' ? __('Supplier') : __('Customer'),
            'routeName' => $kind === 'payable' ? 'accounting.reports.payable-aging' : 'accounting.reports.receivable-aging',
            'filters' => ['asOf' => $asOf],
            'buckets' => collect($report['buckets'])->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'rows' => $report['rows']->map(fn (array $row) => [
                'client' => UserCellData::of($row['client']),
                'invoices' => (int) $row['invoices'],
                'amounts' => collect($report['buckets'])->map(fn (string $label, string $key) => $money((float) $row[$key])),
                'overdue' => (float) $row['older'] > 0,
                'total' => Number::money($row['total']),
            ])->values(),
            'totals' => array_map(fn (float $value) => Number::money($value), $report['totals']),
        ]);
    }

    public function agingExport(ReportRequest $request, string $kind = 'receivable'): BinaryFileResponse
    {
        $kind = $kind === 'payable' ? 'payable' : 'receivable';
        $asOf = $request->dateFilter('asOf') ?? now()->toDateString();
        $report = $this->reports->aging($kind, $asOf);
        $keys = array_keys($report['buckets']);

        $lines = $report['rows']->map(fn (array $row) => [
            $row['client']?->name ?? '-',
            (int) $row['invoices'],
            ...array_map(fn (string $key) => (float) $row[$key] > 0 ? $row[$key] : null, $keys),
            $row['total'],
        ])->push([__('Total'), null, ...array_map(fn (string $key) => $report['totals'][$key], $keys), $report['totals']['total']]);

        return $this->excel->downloadRows($lines, $this->filename($kind.'-aging', $asOf), [
            $kind === 'payable' ? __('Supplier') : __('Customer'),
            __('Invoices'),
            ...array_values($report['buckets']),
            __('Total'),
        ]);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array{from: ?string, to: ?string, withGroups: bool, includeZero: bool}
     */
    private function trialBalanceFilters(ReportRequest $request): array
    {
        $to = $request->dateFilter('to', now()->toDateString());

        return [
            'from' => $request->dateFilter('from', $this->reports->fiscalYearStart($to ?? now()->toDateString())),
            'to' => $to,
            'withGroups' => $request->flagFilter('withGroups', true),
            'includeZero' => $request->flagFilter('includeZero', false),
        ];
    }

    /**
     * @return array{account_id: ?int, from: ?string, to: ?string, search: string}
     */
    private function ledgerFilters(ReportRequest $request): array
    {
        return [
            'account_id' => $request->idFilter('account_id'),
            'from' => $request->dateFilter('from'),
            'to' => $request->dateFilter('to'),
            'search' => $request->textFilter('search'),
        ];
    }

    /**
     * قائمة الدخل: بلا تواريخ في الرابط تبدأ من اول السنة المالية حتى اليوم (كما في الخدمة).
     *
     * @return array<string, mixed>
     */
    private function incomeStatementReport(ReportRequest $request): array
    {
        $to = $request->dateFilter('to', now()->toDateString());

        return $this->reports->incomeStatement($request->dateFilter('from'), $to);
    }

    /**
     * قسم من الميزانية او قائمة الدخل: الحسابات بمبالغها بطبيعة القسم، وسطور اضافية، والمجموع من الخدمة.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array{label: string, amount: string}>  $extra
     * @return array<string, mixed>
     */
    private function section(string $title, Collection $rows, float $total, array $extra = []): array
    {
        return [
            'title' => $title,
            'rows' => $rows->map(fn (array $row) => [
                'account_id' => (int) $row['account_id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'amount' => Number::money($row['amount']),
            ])->values()->all(),
            'extra' => $extra,
            'total' => Number::money($total),
        ];
    }

    /**
     * جانب من الرصيد: المبلغ اذا كان في هذا الجانب ($sign = 1 مدين، -1 دائن)، والا فراغ.
     */
    private function side(float $value, int $sign): string
    {
        return $value * $sign > 0 ? Number::money($value * $sign) : '';
    }

    /**
     * الرصيد السالب (دائن) بين قوسين كما في دفتر الاستاذ.
     */
    private function signed(float $value): string
    {
        return $value < 0 ? '('.Number::money(-$value).')' : Number::money($value);
    }

    private function filename(string $name, ?string $date): string
    {
        return $name.'-'.($date ?: now()->toDateString()).'.xlsx';
    }
}
