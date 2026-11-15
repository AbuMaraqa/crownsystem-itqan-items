<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Services\FiscalYearService;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\SalesOrderService;
use Modules\Accounting\Services\StockAdjustmentService;
use Modules\Accounting\Support\Number;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * التقارير المالية في لوحة Vue: ميزان المراجعة، دفتر الاستاذ، الميزانية، قائمة الدخل،
 * اعمار الديون وسجل الزبون؛ الفلاتر من الرابط، التصدير الى Excel، والصلاحيات.
 */
class ReportPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['Accounting Report View List', 'Customer History View', 'Journal Entry View'];

    private Currency $currency;

    private string $today;

    private string $yearStart;

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
        $years->create(['name' => (string) ($year - 1), 'starts_at' => ($year - 1).'-01-01', 'ends_at' => ($year - 1).'-12-31']);
        $years->create(['name' => (string) $year, 'starts_at' => $year.'-01-01', 'ends_at' => $year.'-12-31', 'is_default' => true]);

        $this->seed(DefaultChartOfAccountsSeeder::class);
        $this->seed(AccountMappingSeeder::class);

        $this->today = now()->toDateString();
        $this->yearStart = $year.'-01-01';
    }

    // ------------------------------------------------------------ trial balance

    public function test_trial_balance_shows_opening_movement_closing_and_totals(): void
    {
        $this->postSampleEntries();

        $this->get(route('accounting.reports.trial-balance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Report/TrialBalance')
                ->where('filters.from', $this->yearStart)
                ->where('filters.to', $this->today)
                ->where('filters.withGroups', true)
                ->where('filters.includeZero', false)
                ->where('rows', function (Collection $rows) {
                    $cash = $rows->firstWhere('code', '1110');

                    return $cash['opening_debit'] === '1000'
                        && $cash['debit'] === '400'
                        && $cash['closing_debit'] === '1400'
                        && $cash['closing_credit'] === ''
                        && $rows->contains(fn ($row) => $row['is_postable'] === false);
                })
                ->where('totals.opening_debit', '1000')
                ->where('totals.opening_credit', '1000')
                ->where('totals.debit', '400')
                ->where('totals.credit', '400')
                ->where('totals.closing_debit', '1400')
                ->where('totals.closing_credit', '1400')
                ->where('balanced', true));

        // بلا حسابات اب، وبلا تاريخ بداية (كل الحركات منذ البداية).
        $this->get(route('accounting.reports.trial-balance').'?from=&withGroups=0&includeZero=0')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', null)
                ->where('filters.withGroups', false)
                ->where('rows', fn (Collection $rows) => $rows->every(fn ($row) => $row['is_postable'])
                    && $rows->firstWhere('code', '1110')['opening_debit'] === ''
                    && $rows->firstWhere('code', '1110')['debit'] === '1400'));
    }

    public function test_trial_balance_rejects_a_bad_date(): void
    {
        $this->get(route('accounting.reports.trial-balance', ['to' => 'not-a-date']))
            ->assertSessionHasErrors('to');
    }

    // ------------------------------------------------------------ ledger

    public function test_ledger_runs_the_balance_of_one_account_and_links_to_its_entries(): void
    {
        [, $sale] = $this->postSampleEntries();
        $cash = $this->account('1110');

        $this->get(route('accounting.reports.ledger', ['account_id' => $cash->id, 'from' => $this->yearStart]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Report/GeneralLedger')
                ->where('account.value', $cash->id)
                ->where('showBalance', true)
                ->where('showOpening', true)
                ->where('ledger.opening', '1000')
                ->where('ledger.debit', '400')
                ->where('ledger.closing', '1400')
                ->has('lines', 1)
                ->where('lines.0.entry_id', $sale->id)
                ->where('lines.0.entry_number', $sale->entry_number)
                ->where('lines.0.balance', '1400'));

        // كل الحسابات: بلا رصيد جارٍ، والصفحات من الرابط.
        $this->get(route('accounting.reports.ledger', ['per_page' => 10]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('showBalance', false)
                ->where('meta.total', 4)
                ->where('meta.per_page', 10)
                ->where('ledger.debit', '1400')
                ->where('ledger.credit', '1400'));

        $this->get(route('accounting.reports.ledger', ['search' => $sale->entry_number]))
            ->assertInertia(fn (Assert $page) => $page->where('meta.total', 2));

        // الحساب الدائن يظهر رصيده بين قوسين.
        $this->get(route('accounting.reports.ledger', ['account_id' => $this->account('4100')->id]))
            ->assertInertia(fn (Assert $page) => $page->where('ledger.closing', '(400)'));

        $this->getJson(route('accounting.reports.ledger.accounts', ['q' => '1110']))
            ->assertOk()
            ->assertJsonPath('0.value', $cash->id);
    }

    // ------------------------------------------------------------ balance sheet & income statement

    public function test_balance_sheet_balances_with_the_unclosed_profit(): void
    {
        $this->postSampleEntries();

        $this->get(route('accounting.reports.balance-sheet'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Report/BalanceSheet')
                ->where('filters.to', $this->today)
                ->has('sections', 3)
                ->where('sections.0.title', __('Assets'))
                ->where('sections.0.rows.0.code', '1110')
                ->where('sections.0.total', '1400')
                ->where('sections.2.rows.0.code', '3100')
                ->where('sections.2.rows.0.amount', '1000')
                ->where('sections.2.extra.0.amount', '400')
                ->where('sections.2.total', '1400')
                ->where('totals.assets', '1400')
                ->where('totals.liabilities_and_equity', '1400')
                ->where('balanced', true));

        // قبل قيد المبيعات: رأس المال وحده.
        $this->get(route('accounting.reports.balance-sheet', ['to' => (now()->year - 1).'-12-31']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.assets', '1000')
                ->has('sections.2.extra', 0));
    }

    public function test_income_statement_defaults_to_the_fiscal_year(): void
    {
        $this->postSampleEntries();

        $this->get(route('accounting.reports.income-statement'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Report/IncomeStatement')
                ->where('filters.from', $this->yearStart)
                ->where('filters.to', $this->today)
                ->where('sections.0.rows.0.code', '4100')
                ->where('sections.0.rows.0.amount', '400')
                ->where('sections.0.total', '400')
                ->has('sections.1.rows', 0)
                ->where('net.profit', true)
                ->where('net.amount', '400'));

        $this->get(route('accounting.reports.income-statement', ['from' => $this->yearStart, 'to' => $this->yearStart]))
            ->assertInertia(fn (Assert $page) => $page->has('sections.0.rows', 0)->where('net.amount', '0'));
    }

    // ------------------------------------------------------------ aging & customer history

    public function test_aging_lists_open_invoices_by_party(): void
    {
        $client = User::factory()->create(['name' => 'عميل الاعمار']);
        $invoice = $this->postedInvoice($client, $this->product('مقطع المنيوم'), 3, 10);

        $this->get(route('accounting.reports.receivable-aging'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Report/Aging')
                ->where('kind', 'receivable')
                ->where('title', __('Receivables Aging'))
                ->where('routeName', 'accounting.reports.receivable-aging')
                ->where('filters.asOf', $this->today)
                ->has('buckets', 5)
                ->where('rows.0.client.id', $client->id)
                ->where('rows.0.client.name', 'عميل الاعمار')
                ->where('rows.0.invoices', 1)
                ->where('rows.0.amounts.current', Number::money($invoice->total_amount))
                ->where('rows.0.amounts.older', '')
                ->where('totals.total', Number::money($invoice->total_amount)));

        // قبل تاريخ الفاتورة لا دين.
        $this->get(route('accounting.reports.receivable-aging', ['asOf' => now()->subYear()->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 0));

        $this->get(route('accounting.reports.payable-aging'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kind', 'payable')
                ->where('title', __('Payables Aging'))
                ->has('rows', 0));
    }

    public function test_customer_history_renders_every_tab_from_the_url(): void
    {
        $client = User::factory()->create(['name' => 'عميل السجل']);
        $product = $this->product('مقطع المنيوم');
        $this->postedInvoice($client, $product, 3, 9.75);

        $this->get(route('accounting.reports.customer-history'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Report/CustomerHistory')
                ->where('client', null)
                ->missing('summary'));

        $this->get(route('accounting.reports.customer-history', ['client' => $client->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.tab', 'statement')
                ->where('client.name', 'عميل السجل')
                ->where('clientOption.value', $client->id)
                ->has('summary', 5)
                ->where('statement.has_account', true)
                ->has('statement.rows', 1)
                ->has('statement.rows.0.details', 0)
                ->missing('documents'));

        $this->get(route('accounting.reports.customer-history', ['client' => $client->id, 'mode' => 'detailed']))
            ->assertInertia(fn (Assert $page) => $page->has('statement.rows.0.details', 1));

        $this->get(route('accounting.reports.customer-history', ['client' => $client->id, 'tab' => 'documents', 'group' => 'sales']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents', fn (Collection $documents) => $documents->pluck('type')->contains(__('Sales Invoice')))
                ->missing('statement'));

        $this->get(route('accounting.reports.customer-history', ['client' => $client->id, 'tab' => 'items']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.0.name', 'مقطع المنيوم')
                ->where('items.0.last_sale_price', '9.75 ILS'));

        $this->get(route('accounting.reports.customer-history', ['client' => $client->id, 'tab' => 'movements', 'product' => $product->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('movements', 1)
                ->where('movements.0.unit_price', '9.75 ILS')
                ->whereNot('movementProduct', null));

        $this->get(route('accounting.reports.customer-history', ['client' => $client->id, 'tab' => 'movements', 'movement_search' => 'زاوية']))
            ->assertInertia(fn (Assert $page) => $page->has('movements', 0));

        // تبويب غير معروف يعود لكشف الحساب.
        $this->get(route('accounting.reports.customer-history', ['client' => $client->id, 'tab' => 'other']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.tab', 'statement')->has('statement'));

        $this->getJson(route('accounting.reports.customer-history.clients', ['q' => 'عميل السجل']))
            ->assertOk()
            ->assertJsonPath('0.value', $client->id);
    }

    // ------------------------------------------------------------ exports

    public function test_reports_export_to_excel_with_the_page_filters(): void
    {
        $this->postSampleEntries();

        $this->get(route('accounting.reports.trial-balance.export', ['to' => $this->today]))
            ->assertOk()
            ->assertDownload('trial-balance-'.$this->today.'.xlsx');

        $this->get(route('accounting.reports.ledger.export', ['account_id' => $this->account('1110')->id, 'from' => $this->yearStart, 'to' => $this->today]))
            ->assertOk()
            ->assertDownload('ledger-'.$this->today.'.xlsx');

        $this->get(route('accounting.reports.balance-sheet.export'))
            ->assertOk()
            ->assertDownload('balance-sheet-'.$this->today.'.xlsx');

        $this->get(route('accounting.reports.income-statement.export'))
            ->assertOk()
            ->assertDownload('income-statement-'.$this->today.'.xlsx');

        $this->get(route('accounting.reports.receivable-aging.export'))
            ->assertOk()
            ->assertDownload('receivable-aging-'.$this->today.'.xlsx');

        $this->get(route('accounting.reports.payable-aging.export', ['asOf' => $this->yearStart]))
            ->assertOk()
            ->assertDownload('payable-aging-'.$this->yearStart.'.xlsx');
    }

    // ------------------------------------------------------------ permissions

    public function test_reports_need_their_permissions(): void
    {
        $this->signIn([]);

        foreach ([
            'accounting.reports.trial-balance', 'accounting.reports.trial-balance.export',
            'accounting.reports.ledger', 'accounting.reports.ledger.export', 'accounting.reports.ledger.accounts',
            'accounting.reports.balance-sheet', 'accounting.reports.balance-sheet.export',
            'accounting.reports.income-statement', 'accounting.reports.income-statement.export',
            'accounting.reports.receivable-aging', 'accounting.reports.receivable-aging.export',
            'accounting.reports.payable-aging', 'accounting.reports.payable-aging.export',
            'accounting.reports.customer-history', 'accounting.reports.customer-history.clients',
        ] as $name) {
            $this->get(route($name))->assertForbidden();
        }

        // سجل الزبون بصلاحيته وحدها، دون التقارير المالية.
        $this->signIn(['Customer History View']);
        $this->get(route('accounting.reports.customer-history'))->assertOk();
        $this->get(route('accounting.reports.trial-balance'))->assertForbidden();

        $this->signIn(['Accounting Report View List']);
        $this->get(route('accounting.reports.trial-balance'))->assertOk();
        $this->get(route('accounting.reports.customer-history'))->assertForbidden();
    }

    // ------------------------------------------------------------ helpers

    private function signIn(array $permissions = self::PERMISSIONS): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    /**
     * رأس مال 1000 في السنة الماضية، ومبيعات نقدية 400 اليوم.
     *
     * @return array{0: JournalEntry, 1: JournalEntry}
     */
    private function postSampleEntries(): array
    {
        return [
            $this->postEntry((now()->year - 1).'-06-01', '1110', '3100', 1000),
            $this->postEntry($this->today, '1110', '4100', 400),
        ];
    }

    private function postEntry(string $date, string $debitCode, string $creditCode, float $amount): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        return $entries->post($entries->createDraft(['entry_date' => $date], [
            ['account_id' => $this->account($debitCode)->id, 'currency_id' => $this->currency->id, 'exchange_rate' => 1, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $this->account($creditCode)->id, 'currency_id' => $this->currency->id, 'exchange_rate' => 1, 'debit' => 0, 'credit' => $amount],
        ]));
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    private function postedInvoice(User $client, Product $product, float $quantity, float $price): SalesInvoice
    {
        $order = app(SalesOrderService::class)->create([
            'client_id' => $client->id,
            'warehouse_id' => $this->warehouse()->id,
            'order_date' => $this->today,
            'currency_id' => $this->currency->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price]],
        ]);
        $invoices = app(SalesInvoiceService::class);

        return $invoices->post($invoices->createFromOrder($order, ['invoice_date' => $this->today]));
    }

    private function warehouse(): Warehouse
    {
        $warehouse = Warehouse::query()->where('code', 'WH1')->first();

        if ($warehouse) {
            return $warehouse;
        }

        $warehouse = Warehouse::create(['code' => 'WH1', 'is_active' => true]);
        $warehouse->translateOrNew('ar')->name = 'المخزن الرئيسي';
        $warehouse->save();

        return $warehouse;
    }

    private function product(string $name): Product
    {
        $product = new Product([
            'slug' => 'test-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'base_price' => 10,
            'cost_price' => 8,
            'sale_price' => 10,
            'pricing_type' => 'simple',
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        // رصيد افتتاحي يكفي للبيع، فترحيل فاتورة المبيعات يُخرج البضاعة من المخزن.
        $stock = app(StockAdjustmentService::class);
        $stock->post($stock->create([
            'type' => 'opening',
            'warehouse_id' => $this->warehouse()->id,
            'items' => [['product_id' => $product->id, 'quantity' => 100, 'unit_cost' => 8]],
        ]));

        return $product;
    }
}
