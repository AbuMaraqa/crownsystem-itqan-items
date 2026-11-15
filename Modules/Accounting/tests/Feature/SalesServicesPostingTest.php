<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\SalesOrderItem;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Services\ReceiptVoucherService;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\SalesLedgerAccounts;
use Modules\Accounting\Services\SalesOrderService;
use Modules\Accounting\Services\SalesReturnService;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Services\StockAdjustmentService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * بيع الخدمات: مواد العميل بلا قيمة، الخدمة بايرادها وتكلفتها، والقيد على حساب
 * العميل الفرعي. مثال المستخدم: عميل احضر خام ore فقُدّمت عليه خدمة بالكيلوغرام.
 */
class SalesServicesPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Currency $currency;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->client = User::factory()->create(['name' => 'عميل الخدمات']);
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

        // البيع من المخزون الدائم: البضاعة تخرج من مخزن فيه رصيد.
        $this->warehouse = Warehouse::create(['code' => 'WH1', 'is_active' => true]);
        $this->warehouse->translateOrNew('ar')->name = 'المخزن الرئيسي';
        $this->warehouse->save();
    }

    public function test_order_keeps_services_apart_from_goods_with_cost_and_customer_materials(): void
    {
        $ore = $this->product('خام ore');
        $service = $this->service('معالجة الخام', price: 0.2, cost: 0.08, pricingType: 'weight');

        $order = app(SalesOrderService::class)->create($this->orderData([
            ['product_id' => $service->id, 'quantity' => 500, 'unit_price' => 0.2, 'unit_cost' => 0.08],
        ], customerMaterials: [
            ['product_id' => $ore->id, 'quantity' => 1, 'weight' => 500, 'received_date' => now()->toDateString()],
        ]));

        $line = $order->items->first();

        $this->assertTrue($line->isService());
        $this->assertSame('weight', $line->pricing_basis);
        $this->assertSame('internal', $line->cost_source);
        $this->assertSame(100.0, (float) $line->line_total);
        $this->assertSame(40.0, (float) $line->cost_total);
        $this->assertSame(0.0, (float) $line->total_weight);
        $this->assertSame(100.0, (float) $order->total_amount);

        // مادة العميل مسجلة بوزنها، ولا اثر لها على قيمة الطلبية.
        $this->assertCount(1, $order->customerMaterials);
        $this->assertSame('خام ore', $order->customerMaterials->first()->description);
        $this->assertSame(500.0, (float) $order->customerMaterials->first()->weight);
    }

    public function test_service_line_is_invoiced_by_its_quantity_not_by_weight(): void
    {
        $service = $this->service('معالجة بالكيلو', pricingType: 'weight');
        $line = new SalesOrderItem(['product_id' => $service->id, 'line_type' => 'service', 'quantity' => 750, 'total_weight' => 0]);

        $this->assertSame(750.0, $line->invoiceQuantity());
    }

    public function test_invoice_posts_revenue_by_type_internal_service_cost_and_customer_sub_account(): void
    {
        $tax = Tax::create(['name' => 'VAT', 'code' => 'VAT', 'rate' => 10, 'is_active' => true]);
        $goods = $this->product('مقطع المنيوم');
        $service = $this->service('معالجة الخام', price: 0.2, cost: 0.1);

        $invoice = $this->postedInvoice([
            ['product_id' => $goods->id, 'quantity' => 10, 'unit_price' => 10],
            ['product_id' => $service->id, 'quantity' => 500, 'unit_price' => 0.2, 'unit_cost' => 0.1],
        ], ['tax_id' => $tax->id, 'order_discount' => 20]);

        // صافي 200 − خصم 20 = 180، ضريبة 18، الاجمالي 198.
        $this->assertSame(198.0, (float) $invoice->total_amount);

        $entry = $invoice->journalEntry()->with('lines')->first();
        $customerAccount = Account::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame('1130', $customerAccount->parent->code);
        $this->assertLine($entry, $customerAccount->code, debit: 198);
        // خصم الفاتورة يوزَّع على الايرادات بنسبة صافي كل بند.
        $this->assertLine($entry, '4100', credit: 90);
        $this->assertLine($entry, '4110', credit: 90);
        $this->assertLine($entry, '2140', credit: 18);
        // تكلفة الخدمة الداخلية: من حـ/ تكلفة الخدمات الى حـ/ تكاليف خدمات محمّلة.
        $this->assertLine($entry, '5160', debit: 50);
        $this->assertLine($entry, '5170', credit: 50);
        $this->assertTrue($entry->isBalanced());
    }

    public function test_external_transport_cost_is_owed_to_the_provider_and_uses_transport_accounts(): void
    {
        $provider = User::factory()->create(['name' => 'شركة النقل']);
        $transport = $this->service('نقل', price: 300, cost: 200, kind: 'transport', costSource: 'external');

        $invoice = $this->postedInvoice([
            ['product_id' => $transport->id, 'quantity' => 1, 'unit_price' => 300, 'unit_cost' => 200, 'service_provider_id' => $provider->id],
        ]);

        $line = $invoice->items->first();
        $this->assertSame('trip', $line->pricing_basis);
        $this->assertSame('external', $line->cost_source);

        $entry = $invoice->journalEntry()->with('lines')->first();
        $providerAccount = Account::query()->where('user_id', $provider->id)->firstOrFail();

        $this->assertSame('2110', $providerAccount->parent->code);
        $this->assertLine($entry, '4150', credit: 300);
        $this->assertLine($entry, '5320', debit: 200);
        $this->assertLine($entry, $providerAccount->code, credit: 200);
    }

    public function test_unregistered_external_provider_cost_is_an_accrued_expense(): void
    {
        $transport = $this->service('نقل', price: 300, cost: 150, kind: 'transport', costSource: 'external');

        $invoice = $this->postedInvoice([
            ['product_id' => $transport->id, 'quantity' => 2, 'unit_price' => 300, 'unit_cost' => 150],
        ]);

        $entry = $invoice->journalEntry()->with('lines')->first();

        $this->assertLine($entry, '5320', debit: 300);
        $this->assertLine($entry, '2120', credit: 300);
    }

    public function test_service_card_accounts_override_the_default_mapping(): void
    {
        $service = $this->service('خدمة قص', price: 50);
        $service->update(['revenue_account_id' => Account::query()->where('code', '4200')->value('id')]);

        $invoice = $this->postedInvoice([
            ['product_id' => $service->id, 'quantity' => 2, 'unit_price' => 50, 'unit_cost' => 0],
        ]);

        $entry = $invoice->journalEntry()->with('lines')->first();

        $this->assertLine($entry, '4200', credit: 100);
        // بلا تكلفة لا قيد تكلفة.
        $this->assertFalse($entry->lines->contains(fn ($line) => $line->account_id === Account::query()->where('code', '5160')->value('id')));
    }

    public function test_receipt_voucher_credits_the_customer_sub_account(): void
    {
        $voucherService = app(ReceiptVoucherService::class);
        $voucher = $voucherService->create([
            'client_id' => $this->client->id,
            'account_id' => Account::query()->where('code', '1110')->value('id'),
            'voucher_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'amount' => 120,
        ]);

        $voucher = $voucherService->post($voucher);
        $customerAccount = Account::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertLine($voucher->journalEntry()->with('lines')->first(), $customerAccount->code, credit: 120);
    }

    public function test_sales_return_reverses_revenue_by_type_against_the_customer_account(): void
    {
        $goods = $this->product('مقطع المنيوم');
        $service = $this->service('معالجة الخام', price: 0.2, cost: 0.1);
        $invoice = $this->postedInvoice([
            ['product_id' => $goods->id, 'quantity' => 10, 'unit_price' => 10],
            ['product_id' => $service->id, 'quantity' => 500, 'unit_price' => 0.2, 'unit_cost' => 0.1],
        ]);

        $returnService = app(SalesReturnService::class);
        $return = $returnService->post($returnService->create([
            'sales_invoice_id' => $invoice->id,
            'client_id' => $this->client->id,
            'return_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
        ]));

        $entry = $return->journalEntry()->with('lines')->first();
        $customerAccount = Account::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame('service', $return->items->firstWhere('product_id', $service->id)->line_type);
        $this->assertLine($entry, '4100', debit: 100);
        $this->assertLine($entry, '4110', debit: 100);
        $this->assertLine($entry, $customerAccount->code, credit: 200);
        // تكلفة الخدمة المقدمة لا تُعكس بالمرتجع.
        $this->assertFalse($entry->lines->contains(fn ($line) => $line->account_id === Account::query()->where('code', '5170')->value('id')));
    }

    public function test_revenue_allocation_rounding_matches_the_document_exactly(): void
    {
        $amounts = app(SalesLedgerAccounts::class)->allocateRevenue([
            ['account_id' => 1, 'line_total' => 10],
            ['account_id' => 2, 'line_total' => 10],
            ['account_id' => 3, 'line_total' => 10],
        ], 10);

        $this->assertEqualsWithDelta(10.0, array_sum($amounts), 0.00001);
        $this->assertCount(3, $amounts);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function postedInvoice(array $lines, array $extra = []): SalesInvoice
    {
        $this->stockGoods($lines);
        $order = app(SalesOrderService::class)->create($this->orderData($lines, $extra));
        $invoiceService = app(SalesInvoiceService::class);

        return $invoiceService->post($invoiceService->createFromOrder($order));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     * @param  array<int, array<string, mixed>>|null  $customerMaterials
     * @return array<string, mixed>
     */
    private function orderData(array $lines, array $extra = [], ?array $customerMaterials = null): array
    {
        $data = array_merge([
            'client_id' => $this->client->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => $lines,
        ], $extra);

        if ($customerMaterials !== null) {
            $data['customer_materials'] = $customerMaterials;
        }

        return $data;
    }

    /**
     * رصيد افتتاحي للبضاعة المباعة بكلفة الصنف (الخدمات لا مخزون لها).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function stockGoods(array $lines): void
    {
        $goods = collect($lines)->filter(fn (array $line) => Product::query()->whereKey($line['product_id'])->where('item_type', 'goods')->exists());

        if ($goods->isEmpty()) {
            return;
        }

        $service = app(StockAdjustmentService::class);
        $service->post($service->create([
            'type' => 'opening',
            'warehouse_id' => $this->warehouse->id,
            'items' => $goods->map(fn (array $line) => ['product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'unit_cost' => 8])->values()->all(),
        ]));
    }

    private function assertLine(JournalEntry $entry, string $code, float $debit = 0, float $credit = 0): void
    {
        $accountId = Account::query()->where('code', $code)->value('id');
        $line = $entry->lines->firstWhere('account_id', $accountId);

        $this->assertNotNull($line, "No journal line for account {$code}.");
        $this->assertEqualsWithDelta($debit, (float) $line->debit, 0.0001, "Debit of {$code}");
        $this->assertEqualsWithDelta($credit, (float) $line->credit, 0.0001, "Credit of {$code}");
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

        return $product;
    }

    private function service(string $name, float $price = 1, float $cost = 0, string $pricingType = 'simple', string $kind = 'general', string $costSource = 'internal'): Product
    {
        $product = $this->product($name);
        $product->update([
            'item_type' => 'service',
            'service_kind' => $kind,
            'service_cost_source' => $costSource,
            'pricing_type' => $pricingType,
            'sale_price' => $price,
            'base_price' => $price,
            'cost_price' => $cost,
        ]);

        return $product;
    }
}
