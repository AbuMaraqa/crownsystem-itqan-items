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
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Services\CustomerHistoryService;
use Modules\Accounting\Services\ReceiptVoucherService;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\SalesOrderService;
use Modules\Accounting\Services\StockAdjustmentService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * سجل العميل: آخر سعر بيع لكل صنف، كشف الحساب برصيده الجاري، وكل مستنداته.
 */
class CustomerHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Currency $currency;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'Customer History View', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('Customer History View');
        $this->actingAs($user);

        $this->client = User::factory()->create(['name' => 'عميل السجل']);
        $this->currency = Currency::create([
            'code' => 'ILS',
            'symbol' => '₪',
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

        AccountingPeriod::create([
            'fiscal_year_id' => $year->id,
            'name' => now()->format('F Y'),
            'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::OPEN->value(),
        ]);

        $this->seed(DefaultChartOfAccountsSeeder::class);
        $this->seed(AccountMappingSeeder::class);

        $this->warehouse = Warehouse::create(['code' => 'WH1', 'is_active' => true]);
        $this->warehouse->translateOrNew('ar')->name = 'المخزن الرئيسي';
        $this->warehouse->save();
    }

    public function test_last_sale_price_is_taken_from_the_latest_invoice(): void
    {
        $profile = $this->product('مقطع المنيوم');
        $other = $this->product('زاوية');

        $this->postedInvoice([['product_id' => $profile->id, 'quantity' => 10, 'unit_price' => 12]], now()->startOfMonth()->toDateString());
        $this->postedInvoice([
            ['product_id' => $profile->id, 'quantity' => 5, 'unit_price' => 14.5],
            ['product_id' => $other->id, 'quantity' => 2, 'unit_price' => 3],
        ], now()->toDateString());

        $history = app(CustomerHistoryService::class);
        $row = $history->itemPrices($this->client->id)->firstWhere('product_id', $profile->id);

        $this->assertSame(14.5, $row['last_sale_price']);
        $this->assertSame(now()->toDateString(), $row['last_sale_date']);
        $this->assertSame(2, $row['sales_count']);
        $this->assertSame(15.0, $row['sold_quantity']);
        $this->assertSame(12.0, $row['min_sale_price']);
        $this->assertSame('مقطع المنيوم', $row['name']);

        $last = $history->lastSalePrices($this->client->id, [$profile->id, $other->id]);
        $this->assertSame(14.5, $last[$profile->id]['price']);
        $this->assertSame(3.0, $last[$other->id]['price']);

        // عميل آخر لا يرث اسعار هذا العميل.
        $this->assertSame([], $history->lastSalePrices(User::factory()->create()->id, [$profile->id]));
    }

    public function test_statement_runs_the_balance_and_summary_counts_posted_documents(): void
    {
        $product = $this->product('مقطع المنيوم');
        $this->postedInvoice([['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 20]], now()->toDateString());

        $vouchers = app(ReceiptVoucherService::class);
        $vouchers->post($vouchers->create([
            'client_id' => $this->client->id,
            'account_id' => Account::query()->where('code', '1110')->value('id'),
            'voucher_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'amount' => 150,
        ]));

        $history = app(CustomerHistoryService::class);
        $statement = $history->statement($this->client->id);

        $this->assertTrue($statement['has_account']);
        $this->assertCount(2, $statement['rows']);
        $this->assertSame(200.0, $statement['rows'][0]['balance']);
        $this->assertSame(50.0, $statement['closing']);
        $this->assertSame(200.0, $statement['debit']);
        $this->assertSame(150.0, $statement['credit']);

        $summary = $history->summary($this->client->id);
        $this->assertSame(200.0, $summary['net_sales']);
        $this->assertSame(150.0, $summary['receipts']);

        // الطلبية والفاتورة والسند.
        $types = $history->documents($this->client->id)->pluck('type')->sort()->values()->all();
        $this->assertSame(['receipt_voucher', 'sales_invoice', 'sales_order'], $types);
        $this->assertSame(['receipt_voucher'], $history->documents($this->client->id, group: CustomerHistoryService::FINANCE_GROUP)->pluck('type')->all());
    }

    public function test_page_renders_every_tab_for_the_selected_client(): void
    {
        $product = $this->product('مقطع المنيوم');
        $this->postedInvoice([['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 9.75]], now()->toDateString());

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $page = fn (array $query = []) => $this->get(route('accounting.reports.customer-history', ['client' => $this->client->id, ...$query]))->assertOk();

        $page()->assertInertia(fn (Assert $page) => $page->component('Accounting/Report/CustomerHistory')->where('client.name', 'عميل السجل'));

        $page(['tab' => 'documents'])->assertInertia(fn (Assert $page) => $page
            ->where('documents', fn (Collection $documents) => $documents->pluck('type')->contains(__('Sales Invoice'))));
        $page(['tab' => 'items'])->assertInertia(fn (Assert $page) => $page
            ->where('items.0.name', 'مقطع المنيوم')
            ->where('items.0.last_sale_price', fn (string $price) => str_contains($price, '9.75')));
        $page(['tab' => 'movements', 'product' => $product->id])->assertInertia(fn (Assert $page) => $page
            ->where('filters.tab', 'movements')
            ->where('movements.0.unit_price', fn (string $price) => str_contains($price, '9.75')));
        $page(['tab' => 'statement'])->assertInertia(fn (Assert $page) => $page->where('statement.has_account', true)->has('statement.rows', 1));
    }

    public function test_detailed_statement_lists_document_lines_and_print_links(): void
    {
        foreach (['Sales Invoice View', 'Receipt Voucher View'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            auth()->user()->givePermissionTo($permission);
        }

        $product = $this->product('مقطع المنيوم');
        $invoice = $this->postedInvoice([['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 25]], now()->toDateString());

        $vouchers = app(ReceiptVoucherService::class);
        $voucher = $vouchers->post($vouchers->create([
            'client_id' => $this->client->id,
            'account_id' => Account::query()->where('code', '1110')->value('id'),
            'voucher_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'amount' => 60,
        ]));

        $history = app(CustomerHistoryService::class);

        $summary = $history->statement($this->client->id);
        $this->assertSame([], $summary['rows'][0]['details']);
        $this->assertSame(route('accounting.sales-invoices.pdf', $invoice), $summary['rows'][0]['pdf_url']);
        $this->assertSame(route('accounting.receipt-vouchers.pdf', $voucher), $summary['rows'][1]['pdf_url']);

        $detailed = $history->statement($this->client->id, detailed: true);
        $this->assertCount(1, $detailed['rows'][0]['details']);
        $this->assertSame(4.0, $detailed['rows'][0]['details'][0]['quantity']);
        $this->assertSame(100.0, $detailed['rows'][0]['details'][0]['line_total']);
        $this->assertSame(60.0, $detailed['rows'][1]['details'][0]['line_total']);
        $this->assertSame($summary['closing'], $detailed['closing']);

        // طبقة اللغة تعيد التوجيه الى رابط باللغة؛ المطلوب هنا الطباعة نفسها.
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $this->get(route('accounting.receipt-vouchers.pdf', $voucher))->assertOk();
    }

    public function test_item_movements_can_be_searched(): void
    {
        $profile = $this->product('مقطع المنيوم');
        $corner = $this->product('زاوية');
        $this->postedInvoice([
            ['product_id' => $profile->id, 'quantity' => 2, 'unit_price' => 10],
            ['product_id' => $corner->id, 'quantity' => 1, 'unit_price' => 5],
        ], now()->toDateString());

        $history = app(CustomerHistoryService::class);

        $this->assertCount(2, $history->itemMovements($this->client->id));
        $this->assertSame(['زاوية'], $history->itemMovements($this->client->id, search: 'زاوي')->pluck('name')->all());
        $this->assertSame(['مقطع المنيوم'], $history->itemMovements($this->client->id, search: strtolower($profile->sku))->pluck('name')->all());

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $this->get(route('accounting.reports.customer-history', ['client' => $this->client->id, 'tab' => 'movements', 'movement_search' => 'زاوية']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('movements', fn (Collection $movements) => $movements->pluck('name')->all() === ['زاوية']));

        $this->get(route('accounting.reports.customer-history', ['client' => $this->client->id, 'mode' => 'detailed']))
            ->assertInertia(fn (Assert $page) => $page->where('statement.rows.0.details', fn (Collection $details) => $details->pluck('description')->contains(fn ($text) => str_contains((string) $text, 'مقطع المنيوم'))));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function postedInvoice(array $lines, string $date): SalesInvoice
    {
        $order = app(SalesOrderService::class)->create([
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => $date,
            'currency_id' => $this->currency->id,
            'items' => $lines,
        ]);
        $invoices = app(SalesInvoiceService::class);

        return $invoices->post($invoices->createFromOrder($order, ['invoice_date' => $date]));
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
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 100, 'unit_cost' => 8]],
        ]));

        return $product;
    }
}
