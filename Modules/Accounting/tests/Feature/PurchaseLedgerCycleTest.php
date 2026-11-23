<?php

namespace Modules\Accounting\tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\CurrencyExchangeRate;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseReturn;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPaymentMethod;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PaymentVoucherStatus;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\PurchaseReturnStatus;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\FiscalYearService;
use Modules\Accounting\Services\GoodsReceiptService;
use Modules\Accounting\Services\InventoryAccounts;
use Modules\Accounting\Services\PaymentAllocationService;
use Modules\Accounting\Services\PaymentVoucherService;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Services\PurchaseReturnService;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * دورة المشتريات في الدفتر: طلبية ← استلام ← فاتورة (اقفال «بضاعة لم تصل فواتيرها» وفرق
 * السعر) ← سداد وتخصيص (فرق العملة) ← مرتجع (اخراج المخزون) ← الالغاءات، على حساب المورد
 * الفرعي.
 */
class PurchaseLedgerCycleTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Warehouse $warehouse;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->supplier = User::factory()->create(['name' => 'مورد']);
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

    // ------------------------------------------------------------ invoice ↔ GRNI

    public function test_invoice_below_the_order_value_posts_with_a_credit_price_variance(): void
    {
        $product = $this->product('زاوية', 'P1');
        $order = $this->purchaseOrder([[$product, 10, 25]]);
        $this->postReceipt($order, 10);

        $invoice = $this->postInvoiceForOrder($order, 20);

        $this->assertSame(PurchaseInvoiceStatus::POSTED, $invoice->status);
        $this->assertSame(0.0, $this->balance($this->account('2125')));
        $this->assertSame(-50.0, $this->balance($this->account('5115')));
        $this->assertSame(-200.0, $this->balance($this->supplierAccount()));
        $this->assertSame(250.0, $this->balance($this->warehouseAccount()));
        $this->assertJournalLinesAreOneSided($invoice);
    }

    public function test_invoice_above_the_order_value_posts_with_a_debit_price_variance(): void
    {
        $product = $this->product('حلق', 'P2');
        $order = $this->purchaseOrder([[$product, 10, 25]]);
        $this->postReceipt($order, 10);

        $invoice = $this->postInvoiceForOrder($order, 30);

        $this->assertSame(0.0, $this->balance($this->account('2125')));
        $this->assertSame(50.0, $this->balance($this->account('5115')));
        $this->assertSame(-300.0, $this->balance($this->supplierAccount()));
        $this->assertJournalLinesAreOneSided($invoice);
    }

    public function test_invoice_clears_grni_by_the_received_cost_not_the_ordered_value(): void
    {
        // 3 قطع بـ 100: كلفة القطعة 33.333333، فثلاثة سندات (اثنان يدويان وواحد تلقائي)
        // تُقيِّد 99.9999. الطلبية بلا اسعار، فالفاتورة تُنشأ بسعر السطر 33.3333 × 3 = 99.9999
        // وتُقفل المستلم غير المفوتر تماماً بلا فرق سعر.
        $product = $this->product('مسكة', 'P3');
        $order = $this->purchaseOrder([[$product, 3, 100 / 3]]);
        $order->items()->first()->update(['line_total' => 100]);
        $order->update(['sub_total' => 100, 'total_amount' => 100]);
        $this->postReceipt($order->refresh(), 1);
        $this->postReceipt($order, 1);

        $invoice = $this->postInvoiceForOrder($order, null);

        $this->assertSame(3, GoodsReceipt::query()->where('purchase_order_id', $order->id)->count());
        $this->assertSame(3.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(0.0, $this->balance($this->account('2125')));
        $this->assertSame(-99.9999, $this->balance($this->supplierAccount()));
        $this->assertSame(0.0, $this->balance($this->account('5115')));
        $this->assertJournalLinesAreOneSided($invoice);
    }

    public function test_invoice_posts_to_the_supplier_sub_account_not_the_payables_group(): void
    {
        $product = $this->product('مقطع', 'P4');
        $this->postInvoiceWithoutOrder([['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 10]]);

        $sub = Account::query()->findOrFail($this->supplierAccount());
        $this->assertSame((int) $this->account('2110'), (int) $sub->parent_id);
        $this->assertSame(-50.0, $this->balance($sub->id));
        $this->assertSame(0, JournalLine::query()->where('account_id', $this->account('2110'))->count());
    }

    // ------------------------------------------------------------ payments

    public function test_payment_allocation_settles_the_invoice_and_books_the_exchange_difference(): void
    {
        [$usd, $invoiceDate, $paymentDate] = $this->usdWithTwoRates(3.5, 3.6);
        $product = $this->product('مستورد', 'P5');
        $invoice = $this->postInvoiceWithoutOrder([['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10]], [
            'currency_id' => $usd->id,
            'invoice_date' => $invoiceDate,
        ]);

        $this->assertSame(-350.0, $this->balance($this->supplierAccount()));

        $voucher = $this->postVoucher(100, ['currency_id' => $usd->id, 'voucher_date' => $paymentDate]);
        app(PaymentAllocationService::class)->allocate($voucher, $invoice, 100);

        $invoice->refresh();
        $this->assertSame(100.0, (float) $invoice->paid_amount);
        $this->assertSame(0.0, (float) $invoice->outstanding_amount);
        $this->assertSame(100.0, (float) $voucher->refresh()->allocated_amount);
        // سُدِّد بسعر اعلى: خسارة فرق عملة 10 ورصيد المورد صفر.
        $this->assertSame(0.0, $this->balance($this->supplierAccount()));
        $this->assertSame(10.0, $this->balance($this->account('5520')));
    }

    public function test_documents_of_a_closed_month_are_still_settled_with_the_exchange_difference_dated_today(): void
    {
        // فاتورة وسند بالدولار في الشهر السابق، ثم أُقفل الشهر قبل التسوية.
        $lastMonth = AccountingPeriod::create([
            'fiscal_year_id' => FiscalYear::query()->value('id'),
            'name' => 'last month',
            'starts_at' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'ends_at' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::OPEN->value(),
        ]);
        FiscalYear::query()->update(['starts_at' => $lastMonth->starts_at]);
        $usd = Currency::create(['code' => 'USD', 'symbol' => '$', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => 3.5, 'is_active' => true, 'is_default' => false]);
        $invoiceDate = $lastMonth->starts_at->toDateString();
        $paymentDate = $lastMonth->starts_at->copy()->addDay()->toDateString();
        CurrencyExchangeRate::create(['base_currency_id' => $this->currency->id, 'currency_id' => $usd->id, 'rate_date' => $invoiceDate, 'exchange_rate' => 3.5, 'is_active' => true]);
        CurrencyExchangeRate::create(['base_currency_id' => $this->currency->id, 'currency_id' => $usd->id, 'rate_date' => $paymentDate, 'exchange_rate' => 3.6, 'is_active' => true]);

        $invoice = $this->postInvoiceWithoutOrder([['product_id' => $this->product('مستورد', 'P9')->id, 'quantity' => 10, 'unit_price' => 10]], [
            'currency_id' => $usd->id,
            'invoice_date' => $invoiceDate,
        ]);
        $voucher = $this->postVoucher(100, ['currency_id' => $usd->id, 'voucher_date' => $paymentDate]);
        app(FiscalYearService::class)->closePeriod($lastMonth);

        app(PaymentAllocationService::class)->allocate($voucher, $invoice, 100);

        $this->assertSame(0.0, (float) $invoice->refresh()->outstanding_amount);
        $this->assertSame(0.0, $this->balance($this->supplierAccount()));
        $this->assertSame(10.0, $this->balance($this->account('5520')));
        $entry = JournalEntry::query()->where('source_type', 'ExchangeDifference')->sole();
        $this->assertSame(now()->toDateString(), $entry->entry_date->toDateString());
    }

    public function test_payment_voucher_with_a_pending_cheque_cannot_be_cancelled_from_the_voucher(): void
    {
        $voucher = $this->postVoucher(50, [
            'payment_method' => AccountingPaymentMethod::CHEQUE->value(),
            'cheque_number' => 'CHQ-1',
            'cheque_date' => now()->toDateString(),
        ]);

        try {
            app(PaymentVoucherService::class)->cancel($voucher);
            $this->fail('A voucher with a pending cheque must not be cancelled from the voucher.');
        } catch (DomainException) {
            $this->assertSame(PaymentVoucherStatus::POSTED, $voucher->refresh()->status);
        }

        // من سجل الشيكات (fromCheque) يُلغى ويُعكس قيده.
        $cancelled = app(PaymentVoucherService::class)->cancel($voucher, 'شيك ملغى', true);
        $this->assertSame(PaymentVoucherStatus::CANCELLED, $cancelled->status);
        $this->assertSame(JournalEntryStatus::VOIDED, $cancelled->journalEntry->status);
        $this->assertSame(0.0, $this->balance($this->supplierAccount()));
    }

    public function test_cancelling_a_payment_voucher_releases_its_allocations(): void
    {
        $product = $this->product('مقطع', 'P6');
        $invoice = $this->postInvoiceWithoutOrder([['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 25]]);
        $voucher = $this->postVoucher(100);
        app(PaymentAllocationService::class)->allocate($voucher, $invoice, 100);

        app(PaymentVoucherService::class)->cancel($voucher);

        $this->assertSame(100.0, (float) $invoice->refresh()->outstanding_amount);
        $this->assertSame(PaymentVoucherStatus::CANCELLED, $voucher->refresh()->status);
        $this->assertSame(-100.0, $this->balance($this->supplierAccount()));
    }

    // ------------------------------------------------------------ invoice cancel

    public function test_invoice_cancel_is_blocked_by_allocations_and_returns_then_reverses_everything(): void
    {
        $product = $this->product('بروفيل', 'P7');
        $order = $this->purchaseOrder([[$product, 10, 25]]);
        $invoice = $this->postInvoiceForOrder($order, null);
        $service = app(PurchaseInvoiceService::class);

        $voucher = $this->postVoucher(50);
        $allocation = app(PaymentAllocationService::class)->allocate($voucher, $invoice, 50);
        $this->assertCancelFails(fn () => $service->cancel($invoice));
        app(PaymentAllocationService::class)->deallocate($allocation);

        $return = app(PurchaseReturnService::class)->post($this->createReturn($invoice, 1));
        $this->assertCancelFails(fn () => $service->cancel($invoice));
        app(PurchaseReturnService::class)->cancel($return);

        $cancelled = $service->cancel($invoice, 'خطأ في الفاتورة');

        $this->assertSame(PurchaseInvoiceStatus::CANCELLED, $cancelled->status);
        $this->assertSame('خطأ في الفاتورة', $cancelled->cancellation_reason);
        $this->assertSame(JournalEntryStatus::VOIDED, $cancelled->journalEntry->status);
        $this->assertSame(0.0, (float) $cancelled->outstanding_amount);
        // سند الصرف (50) ما زال مرحّلاً غير مخصَّص، فيبقى المورد مديناً به وحده.
        $this->assertSame(50.0, $this->balance($this->supplierAccount()));
        $this->assertSame(0.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(0.0, $this->balance($this->account('2125')));
        $this->assertNotSame(PurchaseOrderStatus::INVOICED, $order->refresh()->status);

        // الطلبية تُفوتر من جديد بعد الالغاء.
        $again = $this->postInvoiceForOrder($order, null);
        $this->assertSame(PurchaseInvoiceStatus::POSTED, $again->status);
        $this->assertSame(10.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(0.0, $this->balance($this->account('2125')));
    }

    public function test_goods_receipt_cannot_be_cancelled_after_its_order_is_invoiced(): void
    {
        $product = $this->product('زاوية', 'P8');
        $order = $this->purchaseOrder([[$product, 5, 10]]);
        $receipt = $this->postReceipt($order, 5);
        $this->postInvoiceForOrder($order, null);

        $this->assertCancelFails(fn () => app(GoodsReceiptService::class)->cancel($receipt));
        $this->assertSame(5.0, app(StockLedgerService::class)->onHand($product->id));
    }

    // ------------------------------------------------------------ purchase returns

    public function test_return_from_a_stock_order_issues_the_goods_and_credits_inventory_and_variance(): void
    {
        $product = $this->product('حلق', 'P9');
        $order = $this->purchaseOrder([[$product, 10, 25]]);
        $invoice = $this->postInvoiceForOrder($order, 30); // فرق سعر مدين 50

        $return = app(PurchaseReturnService::class)->post($this->createReturn($invoice, 4));

        $this->assertSame(PurchaseReturnStatus::POSTED, $return->status);
        $this->assertSame(120.0, (float) $return->total_amount);
        $this->assertSame(6.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(25.0, (float) $return->items->first()->unit_cost);
        $this->assertSame(1, StockMovement::query()->where('type', StockMovementType::PURCHASE_RETURN->value)->count());

        $this->assertSame(150.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(30.0, $this->balance($this->account('5115'))); // 50 − 20
        $this->assertSame(-180.0, $this->balance($this->supplierAccount()));
        $this->assertSame(0.0, $this->balance($this->account('5110')));

        // التخصيص التلقائي على الفاتورة.
        $this->assertSame(120.0, (float) $return->refresh()->allocated_amount);
        $this->assertSame(180.0, (float) $invoice->refresh()->outstanding_amount);
    }

    public function test_return_quantity_cannot_exceed_what_remains_returnable(): void
    {
        $product = $this->product('مقطع', 'P10');
        $invoice = $this->postInvoiceWithoutOrder([['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 10]]);
        app(PurchaseReturnService::class)->post($this->createReturn($invoice, 3));

        $this->assertCancelFails(fn () => $this->createReturn($invoice, 3));

        // مرتجع بلا طلبية يخرج من المخزن ايضاً ويقيد على حساب مخزونه.
        $this->assertSame(2.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(20.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(-20.0, $this->balance($this->supplierAccount()));
    }

    public function test_return_takes_the_party_currency_and_rate_from_its_invoice(): void
    {
        [$usd, $invoiceDate] = $this->usdWithTwoRates(3.5, 3.6);
        $product = $this->product('مستورد', 'P11');
        $invoice = $this->postInvoiceWithoutOrder([['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10]], [
            'currency_id' => $usd->id,
            'invoice_date' => $invoiceDate,
        ]);

        $return = app(PurchaseReturnService::class)->post($this->createReturn($invoice, 2, ['currency_id' => $this->currency->id]));

        $this->assertSame($usd->id, (int) $return->currency_id);
        $this->assertSame(3.5, (float) $return->exchange_rate);
        $this->assertSame(-280.0, $this->balance($this->supplierAccount()));
        $this->assertSame(280.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->totalBalance());
    }

    public function test_cancelling_a_return_puts_the_goods_back_and_reopens_the_invoice(): void
    {
        $product = $this->product('بروفيل', 'P12');
        $order = $this->purchaseOrder([[$product, 10, 25]]);
        $invoice = $this->postInvoiceForOrder($order, null);
        $return = app(PurchaseReturnService::class)->post($this->createReturn($invoice, 4));

        $cancelled = app(PurchaseReturnService::class)->cancel($return, 'رفض المورد');

        $this->assertSame(PurchaseReturnStatus::CANCELLED, $cancelled->status);
        $this->assertSame(JournalEntryStatus::VOIDED, $cancelled->journalEntry->status);
        $this->assertSame(0.0, (float) $cancelled->allocated_amount);
        $this->assertSame(250.0, (float) $invoice->refresh()->outstanding_amount);
        $this->assertSame(10.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(250.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(-250.0, $this->balance($this->supplierAccount()));
        $this->assertSame(0.0, $this->totalBalance());
    }

    // ---------------------------------------------------------------- helpers

    private function assertCancelFails(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a DomainException.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    private function assertJournalLinesAreOneSided(PurchaseInvoice $invoice): void
    {
        $lines = JournalLine::query()->where('journal_entry_id', $invoice->journal_entry_id)->get();

        $this->assertTrue($lines->every(fn (JournalLine $line) => ((float) $line->debit > 0) xor ((float) $line->credit > 0)));
        $this->assertTrue($lines->every(fn (JournalLine $line) => ((float) $line->debit_base > 0) xor ((float) $line->credit_base > 0)));
        $this->assertSame(round((float) $lines->sum('debit_base'), 4), round((float) $lines->sum('credit_base'), 4));
    }

    /**
     * @return array{0: Currency, 1: string, 2: string}
     */
    private function usdWithTwoRates(float $first, float $second): array
    {
        $usd = Currency::create(['code' => 'USD', 'symbol' => '$', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => $first, 'is_active' => true, 'is_default' => false]);
        $invoiceDate = now()->startOfMonth()->toDateString();
        $paymentDate = now()->startOfMonth()->addDay()->toDateString();

        CurrencyExchangeRate::create(['base_currency_id' => $this->currency->id, 'currency_id' => $usd->id, 'rate_date' => $invoiceDate, 'exchange_rate' => $first, 'is_active' => true]);
        CurrencyExchangeRate::create(['base_currency_id' => $this->currency->id, 'currency_id' => $usd->id, 'rate_date' => $paymentDate, 'exchange_rate' => $second, 'is_active' => true]);

        return [$usd, $invoiceDate, $paymentDate];
    }

    private function postReceipt(PurchaseOrder $order, float $quantity): GoodsReceipt
    {
        $service = app(GoodsReceiptService::class);

        return $service->post($service->create([
            'purchase_order_id' => $order->id,
            'receipt_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => $quantity]],
        ]));
    }

    private function postInvoiceForOrder(PurchaseOrder $order, ?float $price): PurchaseInvoice
    {
        $service = app(PurchaseInvoiceService::class);

        if ($price === null) {
            return $service->post($service->createFromOrder($order));
        }

        $item = $order->items()->first();

        return $service->post($service->create([
            'purchase_order_id' => $order->id,
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => [['purchase_order_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity' => (float) $item->quantity, 'unit_price' => $price]],
        ]));
    }

    private function postInvoiceWithoutOrder(array $items, array $extra = []): PurchaseInvoice
    {
        $service = app(PurchaseInvoiceService::class);

        return $service->post($service->create(array_merge([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => $items,
        ], $extra)));
    }

    private function createReturn(PurchaseInvoice $invoice, float $quantity, array $extra = []): PurchaseReturn
    {
        return app(PurchaseReturnService::class)->create(array_merge([
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'items' => [['purchase_invoice_item_id' => $invoice->items()->first()->id, 'quantity' => $quantity]],
        ], $extra));
    }

    private function postVoucher(float $amount, array $extra = []): PaymentVoucher
    {
        $service = app(PaymentVoucherService::class);

        return $service->post($service->create(array_merge([
            'client_id' => $this->supplier->id,
            'account_id' => $this->account('1110'),
            'voucher_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'payment_method' => AccountingPaymentMethod::CASH->value(),
            'amount' => $amount,
        ], $extra)));
    }

    private function product(string $name, string $barcode): Product
    {
        $product = new Product([
            'slug' => 'pur-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => $barcode.'-'.Str::random(4),
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

    /**
     * @param  array<int, array{0: Product, 1: float, 2: float}>  $lines  الصنف، الكمية، السعر
     */
    private function purchaseOrder(array $lines): PurchaseOrder
    {
        $total = round(array_sum(array_map(fn (array $line) => $line[1] * $line[2], $lines)), 4);
        $order = PurchaseOrder::create([
            'reference_no' => 'PO-'.Str::random(6),
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'exchange_rate' => 1,
            'sub_total' => $total,
            'total_amount' => $total,
            'status' => PurchaseOrderStatus::CONFIRMED->value(),
        ]);

        foreach ($lines as [$product, $quantity, $price]) {
            $order->items()->create([
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => $quantity,
                'unit_price' => round($price, 4),
                'line_total' => round($quantity * $price, 4),
            ]);
        }

        return $order->refresh();
    }

    private function supplierAccount(): int
    {
        return (int) Account::query()->where('user_id', $this->supplier->id)->value('id');
    }

    private function warehouseAccount(): int
    {
        return (int) app(InventoryAccounts::class)->warehouseAccountId($this->warehouse->refresh());
    }

    private function account(string $code): int
    {
        app(InventoryAccounts::class)->ensureDefaults();

        return (int) Account::query()->where('code', $code)->value('id');
    }

    /**
     * رصيد الحساب بالعملة الاساسية من القيود المرحّلة والملغاة بعكسها (مدين − دائن).
     */
    private function balance(int $accountId): float
    {
        $lines = JournalLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($query) => $query->whereIn('status', [JournalEntryStatus::POSTED->value(), JournalEntryStatus::VOIDED->value()]))
            ->get(['debit_base', 'credit_base']);

        return round((float) $lines->sum('debit_base') - (float) $lines->sum('credit_base'), 4);
    }

    private function totalBalance(): float
    {
        $lines = JournalLine::query()
            ->whereHas('journalEntry', fn ($query) => $query->whereIn('status', [JournalEntryStatus::POSTED->value(), JournalEntryStatus::VOIDED->value()]))
            ->get(['debit_base', 'credit_base']);

        return round((float) $lines->sum('debit_base') - (float) $lines->sum('credit_base'), 4);
    }
}
