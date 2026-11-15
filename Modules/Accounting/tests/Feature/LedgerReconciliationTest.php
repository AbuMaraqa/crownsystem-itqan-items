<?php

namespace Modules\Accounting\tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\ProductCost;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPaymentMethod;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Accounting\Services\AccountingReportService;
use Modules\Accounting\Services\FiscalYearService;
use Modules\Accounting\Services\InventoryAccounts;
use Modules\Accounting\Services\PaymentAllocationService;
use Modules\Accounting\Services\PaymentVoucherService;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Services\PurchaseReturnService;
use Modules\Accounting\Services\ReceiptVoucherService;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\SalesReturnService;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Accounting\Services\StockTransferService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * تدقيق التوازن عبر الوحدات بارقام معروفة مسبقاً: بعد دورة شراء ← سداد ← بيع ← تحصيل ←
 * مرتجعات ← تحويل، تُحسب النتائج المتوقعة يدوياً وتُقارن بالدفتر، ثم تُفحص الثوابت:
 *
 * - كل قيد مرحّل متوازن، وميزان المراجعة متوازن.
 * - دفتر الموردين (متبقي الفواتير − غير المخصَّص) = رصيد حسابات الموردين في الاستاذ.
 * - دفتر العملاء بنفس الطريقة = رصيد حسابات العملاء.
 * - قيمة حركات المخزون = رصيد حسابات المخزون = قيمة ProductCost، والكميات = الارصدة.
 * - كل قيد مستند مرتبط بمستنده.
 */
class LedgerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Warehouse $warehouse;

    private User $supplier;

    private User $customer;

    private Tax $vat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->supplier = User::factory()->create(['name' => 'Test Supplier A']);
        $this->customer = User::factory()->create(['name' => 'Test Customer A']);
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

        $this->warehouse = $this->warehouse('المخزن الرئيسي', 'WH1');
        $this->vat = Tax::create(['name' => 'VAT 16%', 'code' => 'VAT16', 'rate' => 16, 'is_active' => true]);
    }

    public function test_purchase_to_payment_scenario_matches_the_hand_calculated_figures(): void
    {
        $item = $this->product('Test Item A');
        $invoice = $this->postPurchaseInvoice([['product_id' => $item->id, 'quantity' => 10, 'unit_price' => 100]], ['tax_id' => $this->vat->id]);

        // صافي 1000، ضريبة مدخلات 160، اجمالي 1160.
        $this->assertSame(1000.0, (float) $invoice->sub_total);
        $this->assertSame(160.0, (float) $invoice->tax_amount);
        $this->assertSame(1160.0, (float) $invoice->total_amount);
        $this->assertSame(1000.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(160.0, $this->balance($this->account('1180')));
        $this->assertSame(-1160.0, $this->balance($this->partyAccount($this->supplier)));
        $this->assertSame(10.0, app(StockLedgerService::class)->onHand($item->id, $this->warehouse->id));
        $this->assertSame(100.0, app(StockLedgerService::class)->averageCost($item->id));

        $voucher = $this->postPayment(500);
        app(PaymentAllocationService::class)->allocate($voucher, $invoice, 500);

        $this->assertSame(660.0, (float) $invoice->refresh()->outstanding_amount);
        $this->assertSame(500.0, (float) $invoice->paid_amount);
        $this->assertSame(-660.0, $this->balance($this->partyAccount($this->supplier)));
        $this->assertSame(-500.0, $this->balance($this->account('1110')));
        $this->assertSame(660.0, (float) app(AccountingReportService::class)->aging('payable')['totals']['total']);

        $this->assertLedgerInvariants();
    }

    public function test_full_cycle_keeps_every_subledger_reconciled_with_the_general_ledger(): void
    {
        $item = $this->product('Test Item A');
        $other = $this->product('Test Item B');
        $branchStore = $this->warehouse('مخزن الفرع', 'WH2');

        // شراء: A ‏10 × 100 و B ‏20 × 30 بضريبة 16% = (1000 + 600) × 1.16 = 1856.
        $purchase = $this->postPurchaseInvoice([
            ['product_id' => $item->id, 'quantity' => 10, 'unit_price' => 100],
            ['product_id' => $other->id, 'quantity' => 20, 'unit_price' => 30],
        ], ['tax_id' => $this->vat->id]);
        $this->assertSame(1856.0, (float) $purchase->total_amount);

        // شراء ثانٍ لـ A بسعر 130: المتوسط المرجح = (1000 + 5 × 130) ÷ 15 = 110.
        $this->postPurchaseInvoice([['product_id' => $item->id, 'quantity' => 5, 'unit_price' => 130]]);
        $this->assertSame(110.0, app(StockLedgerService::class)->averageCost($item->id));

        // مرتجع شراء 2 من B: ‏2 × 30 × 1.16 = 69.6 يسدد الفاتورة الاولى.
        $purchaseReturns = app(PurchaseReturnService::class);
        $purchaseReturns->post($purchaseReturns->create([
            'purchase_invoice_id' => $purchase->id,
            'return_date' => now()->toDateString(),
            'items' => [['purchase_invoice_item_id' => $purchase->items()->where('product_id', $other->id)->value('id'), 'quantity' => 2]],
        ]));
        $this->assertSame(1786.4, (float) $purchase->refresh()->outstanding_amount);

        // سداد 1000 يُخصَّص تلقائياً على الاقدم.
        $payment = $this->postPayment(1000);
        app(PaymentAllocationService::class)->allocateAutomatically($payment);
        $this->assertSame(786.4, (float) $purchase->refresh()->outstanding_amount);

        // بيع 6 من A بـ 200 و 10 من B بـ 50 بضريبة 16%: (1200 + 500) × 1.16 = 1972.
        // التكلفة FIFO: 6 × 100 + 10 × 30 = 900.
        $sale = $this->postSalesInvoice([
            ['product_id' => $item->id, 'quantity' => 6, 'unit_price' => 200],
            ['product_id' => $other->id, 'quantity' => 10, 'unit_price' => 50],
        ], ['tax_id' => $this->vat->id]);
        $this->assertSame(1972.0, (float) $sale->total_amount);
        $this->assertSame(1972.0, (float) $sale->outstanding_amount);
        $this->assertSame(900.0, $this->balance($this->account('5100')));
        $this->assertSame(-1700.0, $this->balance($this->account('4100')));
        $this->assertSame(-272.0, $this->balance($this->account('2140')));

        // مرتجع بيع 1 من A: ‏200 × 1.16 = 232 يسدد الفاتورة، وتعود القطعة بكلفة خروجها 100.
        $salesReturns = app(SalesReturnService::class);
        $salesReturns->post($salesReturns->create([
            'sales_invoice_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'items' => [['sales_invoice_item_id' => $sale->items()->where('product_id', $item->id)->value('id'), 'quantity' => 1]],
        ]));
        $this->assertSame(1740.0, (float) $sale->refresh()->outstanding_amount);
        $this->assertSame(800.0, $this->balance($this->account('5100')));

        // تحصيل 1000 جزئي، ثم تحويل 3 من A الى مخزن الفرع.
        $receipt = $this->postReceipt(1000);
        app(PaymentAllocationService::class)->allocateAutomatically($receipt);
        $this->assertSame(740.0, (float) $sale->refresh()->outstanding_amount);

        $transfers = app(StockTransferService::class);
        $transfers->post($transfers->create([
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $branchStore->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['product_id' => $item->id, 'quantity' => 3]],
        ]));

        // الكميات: A ‏15 − 6 + 1 = 10 (7 في الرئيسي و 3 في الفرع)، B ‏20 − 2 − 10 = 8.
        $ledger = app(StockLedgerService::class);
        $this->assertSame(7.0, $ledger->onHand($item->id, $this->warehouse->id));
        $this->assertSame(3.0, $ledger->onHand($item->id, $branchStore->id));
        $this->assertSame(8.0, $ledger->onHand($other->id));
        // النقل من الاقدم: 3 × 100 = 300 للفرع. الرئيسي: 1 × 100 + 5 × 130 + 1 × 100 + 8 × 30 = 1090.
        $this->assertSame(300.0, $this->balance(app(InventoryAccounts::class)->warehouseAccountId($branchStore->refresh())));
        $this->assertSame(1090.0, $this->balance($this->warehouseAccount()));

        $this->assertSame(740.0, $this->balance($this->partyAccount($this->customer)));
        $this->assertSame(740.0, (float) app(AccountingReportService::class)->aging('receivable')['totals']['total']);
        $this->assertSame(-(786.4 + 650.0), $this->balance($this->partyAccount($this->supplier)));

        $this->assertLedgerInvariants();
    }

    public function test_failed_posting_rolls_back_everything_and_leaves_the_draft(): void
    {
        $item = $this->product('Test Item A');
        $this->postPurchaseInvoice([['product_id' => $item->id, 'quantity' => 2, 'unit_price' => 100]]);
        $before = [JournalEntry::query()->count(), StockMovement::query()->count(), JournalLine::query()->count()];

        $service = app(SalesInvoiceService::class);
        $draft = $service->create($this->invoiceData($this->customer, [['product_id' => $item->id, 'quantity' => 5, 'unit_price' => 200]]));

        try {
            $service->post($draft);
            $this->fail('Posting more than the stock on hand must fail.');
        } catch (DomainException) {
            // الرصيد غير كافٍ: يجب ان يُلغى القيد وحركات المخزون معاً.
        }

        $this->assertSame(SalesInvoiceStatus::DRAFT, $draft->refresh()->status);
        $this->assertSame(0.0, (float) $draft->outstanding_amount);
        $this->assertSame($before, [JournalEntry::query()->count(), StockMovement::query()->count(), JournalLine::query()->count()]);
        $this->assertSame(2.0, app(StockLedgerService::class)->onHand($item->id));
        $this->assertLedgerInvariants();
    }

    public function test_repeated_post_requests_never_duplicate_ledger_rows(): void
    {
        $item = $this->product('Test Item A');
        $service = app(PurchaseInvoiceService::class);
        $draft = $service->create($this->invoiceData($this->supplier, [['product_id' => $item->id, 'quantity' => 3, 'unit_price' => 100]]));
        $stale = PurchaseInvoice::query()->findOrFail($draft->id);

        $service->post($draft);

        foreach ([$draft, $stale] as $attempt) {
            try {
                $service->post($attempt);
                $this->fail('A posted purchase invoice must not post again.');
            } catch (DomainException) {
                //
            }
        }

        $this->assertSame(1, JournalEntry::query()->where('sourceable_type', PurchaseInvoice::class)->where('sourceable_id', $draft->id)->count());
        $this->assertSame(3.0, app(StockLedgerService::class)->onHand($item->id));
        $this->assertLedgerInvariants();
    }

    public function test_a_closed_period_blocks_documents_dated_inside_it(): void
    {
        $item = $this->product('Test Item A');
        $period = AccountingPeriod::query()->firstOrFail();
        app(FiscalYearService::class)->closePeriod($period);

        $service = app(PurchaseInvoiceService::class);
        $draft = $service->create($this->invoiceData($this->supplier, [['product_id' => $item->id, 'quantity' => 1, 'unit_price' => 100]]));

        $this->expectException(DomainException::class);

        try {
            $service->post($draft);
        } finally {
            $this->assertSame(0, JournalEntry::query()->count());
            $this->assertSame(0.0, app(StockLedgerService::class)->onHand($item->id));
        }
    }

    // ---------------------------------------------------------------- invariants

    /**
     * الثوابت التي يجب ان تصح بعد اي تسلسل من العمليات (تُحسب مستقلة عن التقارير ثم تُقارن بها).
     */
    private function assertLedgerInvariants(): void
    {
        $posted = [JournalEntryStatus::POSTED->value(), JournalEntryStatus::VOIDED->value()];

        // 1) كل قيد مرحّل متوازن بالعملة الاساسية.
        JournalEntry::query()->whereIn('status', $posted)->with('lines')->get()->each(function (JournalEntry $entry) {
            $this->assertEqualsWithDelta((float) $entry->lines->sum('debit_base'), (float) $entry->lines->sum('credit_base'), 0.00005, 'Unbalanced entry '.$entry->entry_number);

            if ($entry->source_type !== 'Reversal') {
                $this->assertNotNull($entry->sourceable_id ?? $entry->reference_no, 'Untraceable entry '.$entry->entry_number);
            }
        });

        // 2) ميزان المراجعة متوازن ويطابق مجموع سطور القيود.
        $trial = app(AccountingReportService::class)->trialBalance();
        $lines = JournalLine::query()->whereHas('journalEntry', fn ($q) => $q->whereIn('status', $posted));
        $this->assertEqualsWithDelta((float) $trial->sum('debit_base'), (float) $trial->sum('credit_base'), 0.0001);
        $this->assertEqualsWithDelta((float) (clone $lines)->sum('debit_base'), (float) $trial->sum('debit_base'), 0.0001);

        // 3) دفتر الموردين والعملاء = حساباتهم في الاستاذ (متبقي الفواتير − غير المخصَّص من السندات والمرتجعات).
        $allocations = app(PaymentAllocationService::class);

        foreach ([[PurchaseInvoice::class, $this->supplier, -1], [SalesInvoice::class, $this->customer, 1]] as [$invoiceClass, $party, $sign]) {
            $open = (float) $invoiceClass::query()->where('client_id', $party->id)->where('status', 2)->get()
                ->sum(fn ($invoice) => $allocations->outstanding($invoice) * (float) $invoice->exchange_rate);
            $unallocated = $this->unallocatedFor($invoiceClass, $party);

            $this->assertEqualsWithDelta($sign * ($open - $unallocated), $this->balance($this->partyAccount($party)), 0.0001, 'Subledger differs for '.$party->name);
        }

        // 4) المخزون: الحركات = الارصدة (كمية)، وقيمة الحركات = حسابات المخزون = ProductCost.
        $movementQty = StockMovement::query()->selectRaw('product_id, warehouse_id, SUM(quantity) as qty')->groupBy('product_id', 'warehouse_id')->get();

        foreach ($movementQty as $row) {
            $this->assertEqualsWithDelta((float) $row->qty, (float) StockBalance::query()->where('product_id', $row->product_id)->where('warehouse_id', $row->warehouse_id)->sum('quantity'), 0.00005);
        }

        $stockValue = round((float) StockMovement::query()->sum('total_cost'), 4);
        $inventoryAccounts = Warehouse::query()->get()->map(fn (Warehouse $warehouse) => app(InventoryAccounts::class)->warehouseAccountId($warehouse))->unique();
        $glInventory = round($inventoryAccounts->sum(fn (int $id) => $this->balance($id)), 4);

        $this->assertEqualsWithDelta($stockValue, $glInventory, 0.0001, 'Stock ledger value differs from the inventory accounts');
        $this->assertEqualsWithDelta($stockValue, (float) ProductCost::query()->sum('value'), 0.0001, 'Stock ledger value differs from product costs');
    }

    private function unallocatedFor(string $invoiceClass, User $party): float
    {
        $sources = $invoiceClass === PurchaseInvoice::class
            ? [\Modules\Accounting\Entities\PaymentVoucher::class, \Modules\Accounting\Entities\PurchaseReturn::class]
            : [\Modules\Accounting\Entities\ReceiptVoucher::class, \Modules\Accounting\Entities\SalesReturn::class];
        $allocations = app(PaymentAllocationService::class);

        return (float) collect($sources)->sum(fn (string $class) => $class::query()->where('client_id', $party->id)->get()
            ->sum(fn ($source) => $allocations->unallocatedAmount($source) * (float) $source->exchange_rate));
    }

    // ---------------------------------------------------------------- helpers

    private function postPurchaseInvoice(array $items, array $extra = []): PurchaseInvoice
    {
        $service = app(PurchaseInvoiceService::class);

        return $service->post($service->create($this->invoiceData($this->supplier, $items, $extra)));
    }

    private function postSalesInvoice(array $items, array $extra = []): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->post($service->create($this->invoiceData($this->customer, $items, $extra)));
    }

    private function invoiceData(User $party, array $items, array $extra = []): array
    {
        return array_merge([
            'client_id' => $party->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => $items,
        ], $extra);
    }

    private function postPayment(float $amount)
    {
        $service = app(PaymentVoucherService::class);

        return $service->post($service->create([
            'client_id' => $this->supplier->id,
            'account_id' => $this->account('1110'),
            'voucher_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'payment_method' => AccountingPaymentMethod::CASH->value(),
            'amount' => $amount,
        ]));
    }

    private function postReceipt(float $amount)
    {
        $service = app(ReceiptVoucherService::class);

        return $service->post($service->create([
            'client_id' => $this->customer->id,
            'account_id' => $this->account('1110'),
            'voucher_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'payment_method' => AccountingPaymentMethod::CASH->value(),
            'amount' => $amount,
        ]));
    }

    private function warehouse(string $name, string $code): Warehouse
    {
        $warehouse = Warehouse::create(['code' => $code, 'is_active' => true]);
        $warehouse->translateOrNew('ar')->name = $name;
        $warehouse->save();

        return $warehouse;
    }

    private function product(string $name): Product
    {
        $product = new Product([
            'slug' => 'rec-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => 'B-'.Str::random(8),
            'base_price' => 10,
            'cost_price' => 8,
            'sale_price' => 20,
            'pricing_type' => 'simple',
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    private function warehouseAccount(): int
    {
        return (int) app(InventoryAccounts::class)->warehouseAccountId($this->warehouse->refresh());
    }

    private function partyAccount(User $party): int
    {
        return (int) Account::query()->where('user_id', $party->id)->value('id');
    }

    private function account(string $code): int
    {
        app(InventoryAccounts::class)->ensureDefaults();

        return (int) Account::query()->where('code', $code)->value('id');
    }

    private function balance(int $accountId): float
    {
        $lines = JournalLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($query) => $query->whereIn('status', [JournalEntryStatus::POSTED->value(), JournalEntryStatus::VOIDED->value()]))
            ->get(['debit_base', 'credit_base']);

        return round((float) $lines->sum('debit_base') - (float) $lines->sum('credit_base'), 4);
    }
}
