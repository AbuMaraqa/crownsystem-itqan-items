<?php

namespace Modules\Accounting\tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\Cheque;
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPaymentMethod;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\ChequeStatus;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\PaymentVoucherStatus;
use Modules\Accounting\Enums\ReceiptVoucherStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Services\AccountingReportService;
use Modules\Accounting\Services\ChequeService;
use Modules\Accounting\Services\InventoryAccounts;
use Modules\Accounting\Services\PaymentAllocationService;
use Modules\Accounting\Services\PaymentVoucherService;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Services\ReceiptVoucherService;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\SalesReturnService;
use Modules\Accounting\Services\StockAdjustmentService;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * تسوية المبيعات (نظير PurchaseLedgerCycleTest): الفاتورة المرحّلة مفتوحة للتحصيل،
 * والغاؤها بقيد عكسي واعادة البضاعة، والمرتجع يسدد فاتورته، والغاء سند القبض،
 * وارتجاع الشيك يلغي سنده فتعود الفاتورة مفتوحة.
 */
class SalesSettlementCycleTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Warehouse $warehouse;

    private User $customer;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->customer = User::factory()->create(['name' => 'زبون']);
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

    // ------------------------------------------------------------ open for collection

    public function test_a_posted_sales_invoice_is_open_for_receipt_allocation(): void
    {
        $product = $this->stockedProduct(10, 12);
        $invoice = $this->postSalesInvoice([['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20]]);

        $this->assertSame(100.0, (float) $invoice->outstanding_amount);
        $this->assertSame(0.0, (float) $invoice->paid_amount);

        $voucher = $this->postReceipt(60);
        $this->assertTrue(app(PaymentAllocationService::class)->openInvoices($voucher)->contains('id', $invoice->id));

        app(PaymentAllocationService::class)->allocateAutomatically($voucher);

        $invoice->refresh();
        $this->assertSame(60.0, (float) $invoice->paid_amount);
        $this->assertSame(40.0, (float) $invoice->outstanding_amount);
        $this->assertSame(40.0, $this->balance($this->customerAccount()));
    }

    public function test_a_stale_draft_cannot_post_the_invoice_twice(): void
    {
        $product = $this->stockedProduct(10, 12);
        $service = app(SalesInvoiceService::class);
        $draft = $service->create($this->invoiceData([['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 20]]));
        $stale = SalesInvoice::query()->findOrFail($draft->id);

        $service->post($draft);

        try {
            $service->post($stale);
            $this->fail('A posted sales invoice must not be posted again.');
        } catch (DomainException $exception) {
            $this->assertSame(__('Only draft sales invoices can be posted.'), $exception->getMessage());
        }

        // قيد الايراد (Accounting) وقيد التكلفة (Inventory) مرة واحدة لكل منهما.
        $entries = JournalEntry::query()->where('sourceable_type', SalesInvoice::class)->where('sourceable_id', $draft->id);
        $this->assertSame(['Accounting' => 1, 'Inventory' => 1], (clone $entries)->get()->countBy('source_module')->sortKeys()->all());
        $this->assertSame(8.0, app(StockLedgerService::class)->onHand($product->id));
    }

    // ------------------------------------------------------------ cancellation

    public function test_cancelling_a_sales_invoice_reverses_revenue_cost_and_stock_and_frees_the_order(): void
    {
        $product = $this->stockedProduct(10, 12);
        $order = $this->salesOrder([[$product, 6]]);
        $invoice = app(SalesInvoiceService::class)->post(app(SalesInvoiceService::class)->createFromOrder($order));

        $this->assertSame(4.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(120.0, $this->balance($this->customerAccount()));

        $cancelled = app(SalesInvoiceService::class)->cancel($invoice, 'خطأ في الكمية');

        $this->assertSame(SalesInvoiceStatus::CANCELLED, $cancelled->status);
        $this->assertSame('خطأ في الكمية', $cancelled->cancellation_reason);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame(JournalEntryStatus::VOIDED, $cancelled->journalEntry->status);
        $this->assertSame(0.0, (float) $cancelled->outstanding_amount);
        $this->assertSame(InventoryDocumentStatus::CANCELLED, DeliveryNote::query()->where('sales_invoice_id', $invoice->id)->sole()->status);
        $this->assertSame(10.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(0.0, $this->balance($this->customerAccount()));
        $this->assertSame(0.0, $this->balance($this->account('5100')));
        $this->assertSame(120.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->totalBalance());

        // الطلبية تعود قابلة للفوترة.
        $this->assertSame(SalesOrderStatus::PREPARING, $order->refresh()->status);
        $again = app(SalesInvoiceService::class)->createFromOrder($order);
        $this->assertSame(SalesInvoiceStatus::DRAFT, $again->status);
    }

    public function test_cancelling_a_sales_invoice_is_blocked_by_allocations_and_posted_returns(): void
    {
        $product = $this->stockedProduct(10, 12);
        $invoice = $this->postSalesInvoice([['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20]]);
        $voucher = $this->postReceipt(30);
        $allocation = app(PaymentAllocationService::class)->allocate($voucher, $invoice, 30);

        $this->assertCancelBlocked($invoice);
        app(PaymentAllocationService::class)->deallocate($allocation);

        $return = $this->postReturn($invoice, 1);
        $this->assertCancelBlocked($invoice);

        app(SalesReturnService::class)->cancel($return);
        app(SalesInvoiceService::class)->cancel($invoice->refresh());

        $this->assertSame(SalesInvoiceStatus::CANCELLED, $invoice->refresh()->status);
        $this->assertSame(10.0, app(StockLedgerService::class)->onHand($product->id));
        // بقي سند القبض غير مخصَّص: الزبون دائن بـ 30.
        $this->assertSame(-30.0, $this->balance($this->customerAccount()));
    }

    public function test_only_posted_sales_invoices_can_be_cancelled(): void
    {
        $product = $this->stockedProduct(10, 12);
        $draft = app(SalesInvoiceService::class)->create($this->invoiceData([['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20]]));

        $this->expectException(DomainException::class);
        app(SalesInvoiceService::class)->cancel($draft);
    }

    // ------------------------------------------------------------ returns settle the invoice

    public function test_a_sales_return_settles_its_invoice_and_aging_agrees_with_the_ledger(): void
    {
        $product = $this->stockedProduct(10, 12);
        $invoice = $this->postSalesInvoice([['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 20]]);

        $return = $this->postReturn($invoice, 3);

        $this->assertSame(60.0, (float) $return->refresh()->allocated_amount);
        $this->assertSame(140.0, (float) $invoice->refresh()->outstanding_amount);
        $this->assertSame(140.0, $this->balance($this->customerAccount()));
        $this->assertSame(140.0, $this->agingTotal('receivable'));

        app(SalesReturnService::class)->cancel($return);

        $this->assertSame(0.0, (float) $return->refresh()->allocated_amount);
        $this->assertSame(200.0, (float) $invoice->refresh()->outstanding_amount);
        $this->assertSame(200.0, $this->balance($this->customerAccount()));
        $this->assertSame(200.0, $this->agingTotal('receivable'));
    }

    // ------------------------------------------------------------ receipt vouchers

    public function test_cancelling_a_receipt_voucher_releases_its_allocations_and_reverses_its_entry(): void
    {
        $product = $this->stockedProduct(10, 12);
        $invoice = $this->postSalesInvoice([['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20]]);
        $voucher = $this->postReceipt(100);
        app(PaymentAllocationService::class)->allocate($voucher, $invoice, 100);
        $this->assertSame(0.0, (float) $invoice->refresh()->outstanding_amount);

        $cancelled = app(ReceiptVoucherService::class)->cancel($voucher, 'مكرر');

        $this->assertSame(ReceiptVoucherStatus::CANCELLED, $cancelled->status);
        $this->assertSame('مكرر', $cancelled->cancellation_reason);
        $this->assertSame(JournalEntryStatus::VOIDED, $cancelled->journalEntry->status);
        $this->assertSame(0.0, (float) $cancelled->allocated_amount);
        $this->assertSame(100.0, (float) $invoice->refresh()->outstanding_amount);
        $this->assertSame(100.0, $this->balance($this->customerAccount()));
        $this->assertSame(0.0, $this->balance($this->account('1110')));

        $this->expectException(DomainException::class);
        app(ReceiptVoucherService::class)->cancel($cancelled);
    }

    public function test_a_receipt_voucher_with_a_pending_cheque_is_cancelled_from_the_cheque_register(): void
    {
        $voucher = $this->postReceipt(50, [
            'payment_method' => AccountingPaymentMethod::CHEQUE->value(),
            'cheque_number' => 'IN-1',
            'cheque_date' => now()->toDateString(),
        ]);

        try {
            app(ReceiptVoucherService::class)->cancel($voucher);
            $this->fail('A voucher with a pending cheque must be cancelled from the cheques register.');
        } catch (DomainException) {
            $this->assertSame(ReceiptVoucherStatus::POSTED, $voucher->refresh()->status);
        }
    }

    // ------------------------------------------------------------ cheques

    public function test_a_bounced_customer_cheque_cancels_its_voucher_and_reopens_the_invoice(): void
    {
        $product = $this->stockedProduct(10, 12);
        $invoice = $this->postSalesInvoice([['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20]]);
        $voucher = $this->postReceipt(100, [
            'payment_method' => AccountingPaymentMethod::CHEQUE->value(),
            'cheque_number' => 'IN-2',
            'cheque_date' => now()->toDateString(),
        ]);
        app(PaymentAllocationService::class)->allocate($voucher, $invoice, 100);

        $cheque = Cheque::query()->where('chequeable_type', ReceiptVoucher::class)->where('chequeable_id', $voucher->id)->sole();
        app(ChequeService::class)->markBounced($cheque);

        $this->assertSame(ChequeStatus::BOUNCED, $cheque->refresh()->status);
        $this->assertSame(ReceiptVoucherStatus::CANCELLED, $voucher->refresh()->status);
        $this->assertSame(0.0, (float) $voucher->allocated_amount);
        $this->assertSame(100.0, (float) $invoice->refresh()->outstanding_amount);
        $this->assertSame(100.0, $this->balance($this->customerAccount()));
        $this->assertSame(100.0, $this->agingTotal('receivable'));
        // قيد السند عُكس مرة واحدة فقط.
        $this->assertSame(1, JournalEntry::query()->where('reversal_of_entry_id', $voucher->journal_entry_id)->count());
    }

    public function test_a_cancelled_supplier_cheque_cancels_its_payment_voucher_and_reopens_the_invoice(): void
    {
        $product = $this->product('زاوية', 'P1');
        $service = app(PurchaseInvoiceService::class);
        $invoice = $service->post($service->create([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 10]],
        ]));

        $vouchers = app(PaymentVoucherService::class);
        $voucher = $vouchers->post($vouchers->create([
            'client_id' => $this->supplier->id,
            'account_id' => $this->account('1110'),
            'voucher_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'payment_method' => AccountingPaymentMethod::CHEQUE->value(),
            'cheque_number' => 'OUT-1',
            'cheque_date' => now()->toDateString(),
            'amount' => 50,
        ]));
        app(PaymentAllocationService::class)->allocate($voucher, $invoice, 50);
        $this->assertSame(0.0, (float) $invoice->refresh()->outstanding_amount);

        $cheque = Cheque::query()->where('chequeable_type', PaymentVoucher::class)->where('chequeable_id', $voucher->id)->sole();
        app(ChequeService::class)->cancel($cheque);

        $this->assertSame(ChequeStatus::CANCELLED, $cheque->refresh()->status);
        $this->assertSame(PaymentVoucherStatus::CANCELLED, $voucher->refresh()->status);
        $this->assertSame(50.0, (float) $invoice->refresh()->outstanding_amount);
        $this->assertSame(-50.0, $this->balance($this->supplierAccount()));
        $this->assertSame(50.0, $this->agingTotal('payable'));
    }

    // ---------------------------------------------------------------- helpers

    private function assertCancelBlocked(SalesInvoice $invoice): void
    {
        try {
            app(SalesInvoiceService::class)->cancel($invoice->refresh());
            $this->fail('The sales invoice cancellation should have been blocked.');
        } catch (DomainException) {
            $this->assertSame(SalesInvoiceStatus::POSTED, $invoice->refresh()->status);
        }
    }

    private function postSalesInvoice(array $items): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->post($service->create($this->invoiceData($items)));
    }

    private function invoiceData(array $items): array
    {
        return [
            'client_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => $items,
        ];
    }

    private function postReturn(SalesInvoice $invoice, float $quantity): SalesReturn
    {
        $service = app(SalesReturnService::class);

        return $service->post($service->create([
            'sales_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'items' => [['sales_invoice_item_id' => $invoice->items()->first()->id, 'quantity' => $quantity]],
        ]));
    }

    private function postReceipt(float $amount, array $extra = []): ReceiptVoucher
    {
        $service = app(ReceiptVoucherService::class);

        return $service->post($service->create(array_merge([
            'client_id' => $this->customer->id,
            'account_id' => $this->account('1110'),
            'voucher_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'payment_method' => AccountingPaymentMethod::CASH->value(),
            'amount' => $amount,
        ], $extra)));
    }

    /**
     * @param  array<int, array{0: Product, 1: float}>  $lines  بسعر 20 للوحدة
     */
    private function salesOrder(array $lines): SalesOrder
    {
        $total = array_sum(array_map(fn (array $line) => $line[1] * 20, $lines));
        $order = SalesOrder::create([
            'reference_no' => 'SO-'.Str::random(6),
            'client_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'exchange_rate' => 1,
            'sub_total' => $total,
            'total_amount' => $total,
            'status' => SalesOrderStatus::CONFIRMED->value(),
        ]);

        foreach ($lines as [$product, $quantity]) {
            $order->items()->create([
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => $quantity,
                'unit_price' => 20,
                'line_total' => $quantity * 20,
            ]);
        }

        return $order->refresh()->load('items');
    }

    private function stockedProduct(float $quantity, float $unitCost): Product
    {
        $product = $this->product('مسكة', 'S'.Str::random(3));
        $service = app(StockAdjustmentService::class);
        $service->post($service->create([
            'type' => 'opening',
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]],
        ]));

        return $product;
    }

    private function product(string $name, string $barcode): Product
    {
        $product = new Product([
            'slug' => 'set-'.Str::random(8),
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

    private function agingTotal(string $kind): float
    {
        return (float) app(AccountingReportService::class)->aging($kind)['totals']['total'];
    }

    private function warehouseAccount(): int
    {
        return (int) app(InventoryAccounts::class)->warehouseAccountId($this->warehouse->refresh());
    }

    private function customerAccount(): int
    {
        return (int) Account::query()->where('user_id', $this->customer->id)->value('id');
    }

    private function supplierAccount(): int
    {
        return (int) Account::query()->where('user_id', $this->supplier->id)->value('id');
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
