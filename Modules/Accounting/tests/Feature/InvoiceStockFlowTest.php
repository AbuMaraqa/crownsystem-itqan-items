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
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\DeliveryNoteService;
use Modules\Accounting\Services\GoodsReceiptService;
use Modules\Accounting\Services\InventoryAccounts;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\StockAdjustmentService;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Accounting\Services\StockLevelService;
use Modules\Accounting\tests\Feature\Concerns\PriceGroupFixtures;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * الفواتير والمخزون: ترحيل فاتورة الشراء يُدخل بضاعتها المخزن، وترحيل فاتورة المبيعات
 * يُخرجها، سواء كانت من طلبية (بسند استلام/تسليم تلقائي للمتبقي) او بلا طلبية،
 * مع القيود في شجرة الحسابات.
 */
class InvoiceStockFlowTest extends TestCase
{
    use PriceGroupFixtures;
    use RefreshDatabase;

    private Currency $currency;

    private Warehouse $warehouse;

    private User $supplier;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->supplier = User::factory()->create(['name' => 'مورد']);
        $this->customer = User::factory()->create(['name' => 'زبون']);
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
    }

    // ------------------------------------------------------------ purchases

    public function test_posting_a_purchase_invoice_of_an_unreceived_order_puts_its_goods_in_stock(): void
    {
        $product = $this->product('زاوية', 'Q1');
        $order = $this->purchaseOrder([[$product, 10, 25]]);

        $invoice = $this->postPurchaseInvoiceFromOrder($order);

        $ledger = app(StockLedgerService::class);
        $this->assertSame(10.0, $ledger->onHand($product->id, $this->warehouse->id));
        $this->assertSame(25.0, $ledger->averageCost($product->id));
        $this->assertSame(10.0, (float) $product->refresh()->stock_quantity);
        $this->assertSame(0.0, app(StockLevelService::class)->levels([$product->id])[$product->id]['incoming']);

        // البضاعة دخلت بسند استلام مرحّل يحمل رقم الفاتورة.
        $receipt = GoodsReceipt::query()->where('purchase_order_id', $order->id)->sole();
        $this->assertSame(InventoryDocumentStatus::POSTED, $receipt->status);
        $this->assertSame($invoice->reference_no, $receipt->supplier_document_no);

        $this->assertSame(250.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->balance($this->account('2125')));
        $this->assertSame(-250.0, $this->balance($this->supplierAccount()));
        $this->assertSame(0.0, $this->balance($this->account('5110')));
        $this->assertSame(PurchaseOrderStatus::INVOICED, $order->refresh()->status);
    }

    public function test_purchase_invoice_of_a_weight_order_receives_the_sub_item_pieces(): void
    {
        [$order, $subA, $subB, $main] = $this->weightPurchaseOrder();

        $this->postPurchaseInvoiceFromOrder($order);

        $ledger = app(StockLedgerService::class);
        $this->assertSame(10.0, $ledger->onHand($subA->id, $this->warehouse->id));
        $this->assertSame(20.0, $ledger->onHand($subB->id, $this->warehouse->id));
        // الرئيسي بالوزن الفعلي 1000 كغم وكل القيمة 3000، والمقاطع بعددها.
        $this->assertSame(1000.0, $ledger->onHand($main->id));
        $this->assertSame(3.0, $ledger->averageCost($main->id));
        $this->assertSame(0.0, $ledger->averageCost($subA->id));
        $this->assertSame(90.0, (float) $subA->refresh()->stock_weight);
        $this->assertSame(3000.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->balance($this->account('2125')));
    }

    public function test_purchase_invoice_receives_only_what_earlier_receipts_left(): void
    {
        [$order, $subA, $subB] = $this->weightPurchaseOrder();
        app(GoodsReceiptService::class)->post($this->receive($order, [0 => 4]));

        $this->postPurchaseInvoiceFromOrder($order);

        $ledger = app(StockLedgerService::class);
        $this->assertSame(10.0, $ledger->onHand($subA->id));
        $this->assertSame(20.0, $ledger->onHand($subB->id));
        $this->assertSame(2, GoodsReceipt::query()->where('purchase_order_id', $order->id)->count());
        $this->assertSame(3000.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->balance($this->account('2125')));
    }

    public function test_purchase_invoice_of_a_fully_received_order_adds_nothing(): void
    {
        $product = $this->product('حلق', 'Q2');
        $order = $this->purchaseOrder([[$product, 4, 10]]);
        app(GoodsReceiptService::class)->post($this->receive($order, [0 => 4]));

        $this->postPurchaseInvoiceFromOrder($order);

        $this->assertSame(4.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(1, GoodsReceipt::query()->where('purchase_order_id', $order->id)->count());
        $this->assertSame(40.0, $this->balance($this->warehouseAccount()));
    }

    public function test_a_draft_receipt_keeps_its_quantity_until_it_is_posted(): void
    {
        $product = $this->product('مسكة', 'Q3');
        $order = $this->purchaseOrder([[$product, 10, 5]]);
        $draft = $this->receive($order, [0 => 4]);

        $this->postPurchaseInvoiceFromOrder($order);
        $this->assertSame(6.0, app(StockLedgerService::class)->onHand($product->id));

        app(GoodsReceiptService::class)->post($draft);
        $this->assertSame(10.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(0.0, $this->balance($this->account('2125')));
    }

    public function test_purchase_invoice_without_order_receives_goods_at_their_net_cost(): void
    {
        $goods = $this->product('بروفيل', 'Q4');
        $service = $this->service('نقل');

        // البنود 100 + 30 = 130، خصم الفاتورة 13 (10%) → البضاعة 90 والخدمة 27.
        $invoice = $this->postPurchaseInvoice([
            ['product_id' => $goods->id, 'quantity' => 5, 'unit_price' => 20],
            ['product_id' => $service->id, 'quantity' => 1, 'unit_price' => 30],
        ], ['invoice_discount' => 13]);

        $ledger = app(StockLedgerService::class);
        $this->assertSame(5.0, $ledger->onHand($goods->id, $this->warehouse->id));
        $this->assertSame(18.0, $ledger->averageCost($goods->id));
        $this->assertSame(0.0, $ledger->onHand($service->id));

        $this->assertSame(90.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(27.0, $this->balance($this->account('5110')));
        $this->assertSame(-117.0, $this->balance($this->supplierAccount()));
        $this->assertTrue($invoice->journalEntry->isBalanced());

        $movement = StockMovement::query()->where('product_id', $goods->id)->sole();
        $this->assertSame($invoice->reference_no, $movement->reference_no);
        $this->assertSame($invoice->getMorphClass(), $movement->sourceable_type);
    }

    public function test_foreign_currency_purchase_invoice_costs_stock_in_base_currency(): void
    {
        $usd = Currency::create(['code' => 'USD', 'symbol' => '$', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => 3.5, 'is_active' => true]);
        CurrencyExchangeRate::create(['base_currency_id' => $this->currency->id, 'currency_id' => $usd->id, 'rate_date' => now()->toDateString(), 'exchange_rate' => 3.5, 'is_active' => true]);
        $product = $this->product('مقطع مستورد', 'Q5');

        $this->postPurchaseInvoice([['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 10]], ['currency_id' => $usd->id]);

        $this->assertSame(35.0, app(StockLedgerService::class)->averageCost($product->id));
        $this->assertSame(140.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(-140.0, $this->balance($this->supplierAccount()));
    }

    public function test_purchase_invoice_without_order_uses_the_only_warehouse_or_asks_for_one(): void
    {
        $product = $this->product('قاطع', 'Q6');

        // مخزن واحد فقط: يُستعمل ويُحفظ على الفاتورة.
        $invoice = $this->postPurchaseInvoice([['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 5]], ['warehouse_id' => null]);
        $this->assertSame($this->warehouse->id, (int) $invoice->warehouse_id);
        $this->assertSame(2.0, app(StockLedgerService::class)->onHand($product->id, $this->warehouse->id));

        // اكثر من مخزن: يجب اختياره، ولا يتغير شيء.
        $this->warehouse('المخزن الثاني', 'WH2');
        $draft = app(PurchaseInvoiceService::class)->create($this->invoiceData($this->supplier, [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 5]], ['warehouse_id' => null]));

        try {
            app(PurchaseInvoiceService::class)->post($draft);
            $this->fail('A warehouse must be selected.');
        } catch (DomainException $exception) {
            $this->assertSame(__('Select the warehouse that receives the items of this invoice.'), $exception->getMessage());
        }

        $this->assertSame(PurchaseInvoiceStatus::DRAFT, $draft->refresh()->status);
        $this->assertSame(2.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(0, JournalEntry::query()->where('sourceable_type', $draft::class)->where('sourceable_id', $draft->id)->whereIn('status', [2, 3])->count());
    }

    public function test_weight_sub_item_invoiced_by_kilogram_is_stocked_in_pieces(): void
    {
        $main = $this->product('المنيوم سبير', 'MAIN', 'weight');
        $sub = $this->product('عليون K3317', 'W1', 'weight', $main->id, 1.5, 6);

        // 90 كغ ÷ (6 م × 1.5 كغ/م) = 10 قطع؛ الكغم وقيمته 90 × 3 = 270 على صنفه الرئيسي.
        $this->postPurchaseInvoice([['product_id' => $sub->id, 'quantity' => 90, 'unit_price' => 3]]);

        $ledger = app(StockLedgerService::class);
        $this->assertSame(10.0, $ledger->onHand($sub->id));
        $this->assertSame(0.0, $ledger->averageCost($sub->id));
        $this->assertSame(90.0, (float) $sub->refresh()->stock_weight);
        $this->assertSame(90.0, $ledger->onHand($main->id));
        $this->assertSame(3.0, $ledger->averageCost($main->id));
        $this->assertSame(270.0, $this->balance($this->warehouseAccount()));
    }

    public function test_a_main_item_without_pieces_is_stocked_in_kg_from_an_invoice_without_order(): void
    {
        $main = $this->product('المنيوم سبير', 'MAIN', 'weight');
        $this->product('عليون K3317', 'W1', 'weight', $main->id, 1.5, 6);

        $this->postPurchaseInvoice([['product_id' => $main->id, 'quantity' => 100, 'unit_price' => 3]]);

        $this->assertSame(100.0, app(StockLedgerService::class)->onHand($main->id));
        $this->assertSame(100.0, (float) $main->refresh()->stock_weight);
        $this->assertSame(300.0, $this->balance($this->warehouseAccount()));
    }

    public function test_direct_shipment_purchase_invoice_stays_out_of_the_warehouse(): void
    {
        $product = $this->product('بروفيل مباشر', 'Q7');
        $order = $this->purchaseOrder([[$product, 10, 7]]);
        $order->update(['delivery_type' => 'direct', 'direct_customer_id' => $this->customer->id]);

        $this->postPurchaseInvoiceFromOrder($order);

        $this->assertSame(0.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(0, GoodsReceipt::query()->count());
        $this->assertSame(70.0, $this->balance($this->account('5100')));
    }

    // ---------------------------------------------------------------- sales

    public function test_posting_a_sales_invoice_of_an_undelivered_order_issues_its_goods(): void
    {
        $product = $this->product('مسكة', 'S1');
        $this->openingBalance($product, 10, 12);
        $order = $this->salesOrder([[$product, 6]]);

        $invoice = $this->postSalesInvoiceFromOrder($order);

        $this->assertSame(4.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(4.0, (float) $product->refresh()->stock_quantity);
        $this->assertSame(0.0, app(StockLevelService::class)->levels([$product->id])[$product->id]['reserved']);

        $note = DeliveryNote::query()->where('sales_order_id', $order->id)->sole();
        $this->assertSame(InventoryDocumentStatus::POSTED, $note->status);
        $this->assertSame(72.0, (float) $note->total_cost);

        $this->assertSame(72.0, $this->balance($this->account('5100')));
        $this->assertSame(48.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(-120.0, $this->balance($this->account('4100')));
        $this->assertSame(120.0, $this->balance($this->customerAccount()));
        $this->assertSame(SalesInvoiceStatus::POSTED, $invoice->status);
    }

    public function test_sales_invoice_is_blocked_when_stock_is_insufficient(): void
    {
        $product = $this->product('بروفيل', 'S2');
        $this->openingBalance($product, 2, 10);
        $order = $this->salesOrder([[$product, 5]]);
        $invoice = app(SalesInvoiceService::class)->createFromOrder($order);

        try {
            app(SalesInvoiceService::class)->post($invoice);
            $this->fail('Posting should be blocked.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('S2', $exception->getMessage());
        }

        $this->assertSame(SalesInvoiceStatus::DRAFT, $invoice->refresh()->status);
        $this->assertSame(2.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(0, DeliveryNote::query()->count());
        $this->assertSame(0.0, $this->balance($this->account('4100')));
    }

    public function test_sales_invoice_delivers_only_what_earlier_notes_left(): void
    {
        $product = $this->product('حلق', 'S3');
        $this->openingBalance($product, 10, 5);
        $order = $this->salesOrder([[$product, 6]]);
        app(DeliveryNoteService::class)->post(app(DeliveryNoteService::class)->create([
            'sales_order_id' => $order->id,
            'items' => [['sales_order_item_id' => $order->items->first()->id, 'quantity' => 2]],
        ]));

        $this->postSalesInvoiceFromOrder($order);

        $this->assertSame(4.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(2, DeliveryNote::query()->where('sales_order_id', $order->id)->count());
        $this->assertSame(30.0, $this->balance($this->account('5100')));
    }

    public function test_sales_invoice_of_a_weight_order_issues_the_sub_item_pieces(): void
    {
        [$purchase, $subA, $subB] = $this->weightPurchaseOrder();
        $this->postPurchaseInvoiceFromOrder($purchase);

        $order = $this->salesOrder([]);
        $line = $order->items()->create(['product_id' => $subA->main_product_id, 'description' => 'المنيوم', 'quantity' => 1, 'unit_price' => 5, 'total_weight' => 36, 'actual_weight' => 36, 'line_total' => 180]);
        $line->pieces()->create(['product_id' => $subA->id, 'description' => $subA->name, 'piece_count' => 4, 'piece_length' => 6, 'weight_per_meter' => 1.5, 'total_weight' => 36, 'sort_order' => 0]);
        $order->update(['total_amount' => 180, 'sub_total' => 180]);

        $invoice = $this->postSalesInvoiceFromOrder($order->refresh());

        // خرجت 4 قطع من الاول فقط، و36 كغم من الرئيسي بكلفة 3 للكغم = 108.
        $this->assertSame(36.0, (float) $invoice->items->first()->quantity);
        $this->assertSame(6.0, app(StockLedgerService::class)->onHand($subA->id));
        $this->assertSame(20.0, app(StockLedgerService::class)->onHand($subB->id));
        $this->assertSame(54.0, (float) $subA->refresh()->stock_weight);
        $this->assertSame(964.0, app(StockLedgerService::class)->onHand($subA->main_product_id));
        $this->assertSame(108.0, $this->balance($this->account('5100')));
    }

    public function test_direct_supply_sales_invoice_does_not_touch_the_warehouse(): void
    {
        $product = $this->product('بضاعة من المورد', 'S4');
        $order = $this->salesOrder([[$product, 3]]);
        $order->update(['fulfillment_type' => 'direct']);

        $this->postSalesInvoiceFromOrder($order->refresh());

        $this->assertSame(0.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(0, DeliveryNote::query()->count());
        $this->assertSame(-60.0, $this->balance($this->account('4100')));
    }

    public function test_sales_invoice_without_order_issues_goods_and_books_their_cost(): void
    {
        $product = $this->product('زاوية', 'S5');
        $service = $this->service('تركيب');
        $this->openingBalance($product, 10, 8);

        $invoice = $this->postSalesInvoice([
            ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 20],
            ['product_id' => $service->id, 'quantity' => 1, 'unit_price' => 15],
        ]);

        $this->assertSame(7.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(24.0, $this->balance($this->account('5100')));
        $this->assertSame(56.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(75.0, $this->balance($this->customerAccount()));

        $costEntry = JournalEntry::query()
            ->where('sourceable_type', $invoice::class)
            ->where('sourceable_id', $invoice->id)
            ->where('source_module', InventoryAccounts::SOURCE_MODULE)
            ->sole();
        $this->assertTrue($costEntry->isBalanced());
    }

    public function test_sales_invoice_without_order_is_blocked_without_stock(): void
    {
        $product = $this->product('غير موجود', 'S6');
        $draft = app(SalesInvoiceService::class)->create($this->invoiceData($this->customer, [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20]]));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('S6');

        app(SalesInvoiceService::class)->post($draft);
    }

    // --------------------------------------------------------- cancellation

    public function test_cancelling_a_purchase_invoice_cancels_its_automatic_receipt(): void
    {
        $product = $this->product('زاوية', 'X1');
        $order = $this->purchaseOrder([[$product, 10, 25]]);
        $invoice = $this->postPurchaseInvoiceFromOrder($order);

        app(PurchaseInvoiceService::class)->cancel($invoice);

        $receipt = GoodsReceipt::query()->where('purchase_order_id', $order->id)->sole();
        $this->assertSame($invoice->id, (int) $receipt->purchase_invoice_id);
        $this->assertSame(InventoryDocumentStatus::CANCELLED, $receipt->status);
        $this->assertSame(0.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(0.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->balance($this->account('2125')));
        $this->assertSame(0.0, $this->totalBalance());
    }

    public function test_cancelling_a_purchase_invoice_without_order_takes_its_goods_out_unless_sold(): void
    {
        $product = $this->product('بروفيل', 'X2');
        $sold = $this->postPurchaseInvoice([['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 10]]);
        $this->postSalesInvoice([['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 20]]);

        try {
            app(PurchaseInvoiceService::class)->cancel($sold);
            $this->fail('Goods already sold cannot leave with the cancellation.');
        } catch (DomainException) {
        }

        $this->assertSame(PurchaseInvoiceStatus::POSTED, $sold->refresh()->status);
        $this->assertSame(3.0, app(StockLedgerService::class)->onHand($product->id));

        $other = $this->postPurchaseInvoice([['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 10]]);
        app(PurchaseInvoiceService::class)->cancel($other);

        $this->assertSame(3.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(30.0, $this->balance($this->warehouseAccount()));
    }

    public function test_cancelling_a_sales_invoice_returns_its_goods(): void
    {
        if (! method_exists(SalesInvoiceService::class, 'cancel')) {
            $this->markTestSkipped('Sales invoice cancellation is not available yet.');
        }

        $product = $this->product('مسكة', 'X3');
        $this->openingBalance($product, 10, 12);

        $fromOrder = $this->postSalesInvoiceFromOrder($this->salesOrder([[$product, 6]]));
        $direct = $this->postSalesInvoice([['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 20]]);
        $this->assertSame(1.0, app(StockLedgerService::class)->onHand($product->id));

        app(SalesInvoiceService::class)->cancel($fromOrder);
        app(SalesInvoiceService::class)->cancel($direct);

        $this->assertSame(InventoryDocumentStatus::CANCELLED, DeliveryNote::query()->where('sales_invoice_id', $fromOrder->id)->sole()->status);
        $this->assertSame(10.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(0.0, $this->balance($this->account('5100')));
        $this->assertSame(120.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->balance($this->customerAccount()));
    }

    // ----------------------------------------------------------- full cycle

    public function test_full_cycle_purchase_invoice_then_sales_keeps_stock_and_ledger_in_step(): void
    {
        $product = $this->product('مقطع', 'C1');

        $this->postPurchaseInvoice([['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 8]]);
        $this->postSalesInvoiceFromOrder($this->salesOrder([[$product, 4]]));
        $this->postSalesInvoice([['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 20]]);

        $ledger = app(StockLedgerService::class);
        $this->assertSame(3.0, $ledger->onHand($product->id));
        $this->assertSame(3.0, (float) $product->refresh()->stock_quantity);
        $this->assertSame(8.0, $ledger->averageCost($product->id));

        // قيمة المخزون في الدفتر = قيمته في شجرة الحسابات.
        $this->assertSame(24.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(24.0, round((float) StockMovement::query()->sum('total_cost'), 4));
        $this->assertSame(56.0, $this->balance($this->account('5100')));
        $this->assertSame(-140.0, $this->balance($this->account('4100')));
        $this->assertSame(-80.0, $this->balance($this->supplierAccount()));
        $this->assertSame(140.0, $this->balance($this->customerAccount()));
        $this->assertSame(0.0, $this->balance($this->account('2125')));
        $this->assertSame(0.0, $this->totalBalance());
    }

    // ---------------------------------------------------------------- helpers

    public function test_a_weight_line_without_actual_weight_has_zero_amount_and_still_moves_its_stock(): void
    {
        $main = $this->product('المنيوم سبير', 'MAINZ', 'weight');
        $piece = $this->product('عليون', 'WZ1', 'weight', $main->id, 1.5, 6);
        $simple = $this->product('مقبض', 'QZ1');

        $invoice = $this->postPurchaseInvoice([
            ['product_id' => $main->id, 'unit_price' => 3, 'pieces' => [['product_id' => $piece->id, 'piece_count' => 10]]],
            ['product_id' => $simple->id, 'quantity' => 2, 'unit_price' => 50],
        ]);

        $line = $invoice->items->firstWhere('product_id', $main->id);

        $this->assertSame(0.0, (float) $line->line_total);
        $this->assertSame(0.0, (float) $line->quantity);
        $this->assertSame(90.0, (float) $line->total_weight);
        $this->assertNull($line->actual_weight);
        $this->assertSame(100.0, (float) $invoice->total_amount);
        // وزن المخزون كما هو، والمبلغ وحده تغيّر.
        $this->assertSame(10.0, app(StockLedgerService::class)->onHand($piece->id));
        $this->assertSame(90.0, (float) $piece->refresh()->stock_weight);
        $this->assertSame(100.0, $this->balance($this->warehouseAccount()));

        $sale = $this->postSalesInvoice([
            ['product_id' => $main->id, 'unit_price' => 5, 'pieces' => [['product_id' => $piece->id, 'piece_count' => 4]]],
            ['product_id' => $simple->id, 'quantity' => 1, 'unit_price' => 70],
        ]);

        $this->assertSame(70.0, (float) $sale->total_amount);
        $this->assertSame(6.0, app(StockLedgerService::class)->onHand($piece->id));
        $this->assertSame(54.0, (float) $piece->refresh()->stock_weight);
    }

    /* ---------- سطر مجموعة السعر (المرحلة 2): لا صنف رئيسي، كل لون يتحرك بقطعه وكلفته ---------- */

    public function test_a_price_group_line_of_an_order_issues_each_colour_by_its_pieces_and_cost(): void
    {
        ['white' => $white, 'whiteW45' => $whiteW45, 'meshW45' => $meshW45] = $this->alpineGroups();
        $this->openingBalance($whiteW45, 10, 30);
        $this->openingBalance($meshW45, 10, 40);

        $order = $this->salesOrder([]);
        $line = $order->items()->create(['product_id' => null, 'category_finish_id' => $white->id, 'description' => 'الوبين — أبيض', 'quantity' => 16, 'unit_price' => 20, 'total_weight' => 16.25, 'actual_weight' => 16, 'line_total' => 320]);
        $line->pieces()->create(['product_id' => $whiteW45->id, 'description' => $whiteW45->name, 'piece_count' => 2, 'piece_length' => 6.5, 'weight_per_meter' => 1.25, 'total_weight' => 16.25, 'sort_order' => 0]);
        $order->update(['total_amount' => 320, 'sub_total' => 320]);

        $invoice = $this->postSalesInvoiceFromOrder($order->refresh());

        $invoiceLine = $invoice->items->sole();
        $this->assertNull($invoiceLine->product_id);
        $this->assertSame($white->id, (int) $invoiceLine->category_finish_id);
        $this->assertSame(16.0, (float) $invoiceLine->quantity);
        $this->assertSame(320.0, (float) $invoiceLine->line_total);

        // خرجت قطعتان من الأبيض بكلفتهما (2 × 30)، والميش كما هو، ولا حركة بلا صنف ولا تسوية.
        $this->assertSame(8.0, app(StockLedgerService::class)->onHand($whiteW45->id));
        $this->assertSame(10.0, app(StockLedgerService::class)->onHand($meshW45->id));
        $this->assertSame(60.0, $this->balance($this->account('5100')));
        $this->assertSame(0, StockMovement::query()->where(fn ($query) => $query->whereNull('product_id')->orWhere('product_id', 0))->count());
        $this->assertSame(0, StockMovement::query()->where('type', StockMovementType::INVOICE_ADJUSTMENT->value)->count());
        // وزن الحركة هو الوزن الفعلي للسطر (16)، لا المحسوب (16.25).
        $this->assertSame(-16.0, (float) StockMovement::query()->where('product_id', $whiteW45->id)->where('type', StockMovementType::DELIVERY->value)->sum('weight'));
    }

    public function test_a_delivery_note_delivers_part_of_a_price_group_line_and_the_invoice_the_rest(): void
    {
        ['white' => $white, 'whiteW45' => $whiteW45] = $this->alpineGroups();
        $this->openingBalance($whiteW45, 10, 30);

        $order = $this->salesOrder([]);
        $line = $order->items()->create(['product_id' => null, 'category_finish_id' => $white->id, 'description' => 'الوبين — أبيض', 'quantity' => 24, 'unit_price' => 20, 'total_weight' => 24.375, 'actual_weight' => 24, 'line_total' => 480]);
        $piece = $line->pieces()->create(['product_id' => $whiteW45->id, 'description' => $whiteW45->name, 'piece_count' => 3, 'piece_length' => 6.5, 'weight_per_meter' => 1.25, 'total_weight' => 24.375, 'sort_order' => 0]);
        $order->update(['total_amount' => 480, 'sub_total' => 480]);

        $note = app(DeliveryNoteService::class)->create([
            'sales_order_id' => $order->id,
            'items' => [['sales_order_item_id' => $line->id, 'sales_order_item_piece_id' => $piece->id, 'quantity' => 1]],
        ]);
        // القطعة تحمل نصيبها من الوزن الفعلي: 24 ÷ 3.
        $this->assertSame(8.0, (float) $note->items()->sole()->weight);
        app(DeliveryNoteService::class)->post($note);
        $this->assertSame(9.0, app(StockLedgerService::class)->onHand($whiteW45->id));

        $this->postSalesInvoiceFromOrder($order->refresh());

        $this->assertSame(7.0, app(StockLedgerService::class)->onHand($whiteW45->id));
        $this->assertSame(2, DeliveryNote::query()->where('sales_order_id', $order->id)->count());
        $this->assertSame(90.0, $this->balance($this->account('5100')));
        $this->assertSame(-24.0, (float) StockMovement::query()->where('product_id', $whiteW45->id)->where('type', StockMovementType::DELIVERY->value)->sum('weight'));
        $this->assertSame(0, StockMovement::query()->where('type', StockMovementType::INVOICE_ADJUSTMENT->value)->count());
    }

    public function test_a_price_group_line_without_order_issues_each_colour_by_its_pieces_and_cost(): void
    {
        ['white' => $white, 'whiteW45' => $whiteW45] = $this->alpineGroups();
        $this->openingBalance($whiteW45, 10, 30);

        $invoice = $this->postSalesInvoice([$this->groupLine($white, [[$whiteW45, 2]], 16)]);

        $line = $invoice->items->sole();
        $this->assertNull($line->product_id);
        $this->assertSame($white->id, (int) $line->category_finish_id);
        $this->assertSame('الوبين — أبيض', $line->description);
        $this->assertSame(320.0, (float) $invoice->total_amount);
        $this->assertSame(8.0, app(StockLedgerService::class)->onHand($whiteW45->id));
        $this->assertSame(60.0, $this->balance($this->account('5100')));
        $this->assertSame(-16.0, (float) StockMovement::query()->where('product_id', $whiteW45->id)->where('type', StockMovementType::DELIVERY->value)->sum('weight'));
        $this->assertSame(0, StockMovement::query()->where(fn ($query) => $query->whereNull('product_id')->orWhere('product_id', 0))->count());
    }

    public function test_the_actual_weight_sets_the_amount_and_leaves_stock_weight_on_the_pieces(): void
    {
        $main = $this->product('المنيوم سبير', 'MAINA', 'weight');
        $piece = $this->product('عليون', 'WA1', 'weight', $main->id, 1.5, 6);

        $invoice = $this->postPurchaseInvoice([
            ['product_id' => $main->id, 'unit_price' => 3, 'actual_weight' => 100, 'pieces' => [['product_id' => $piece->id, 'piece_count' => 10]]],
        ]);

        $this->assertSame(300.0, (float) $invoice->total_amount);
        $this->assertSame(90.0, (float) $piece->refresh()->stock_weight);
    }

    public function test_a_document_whose_only_line_has_no_actual_weight_is_saved_but_refused_at_posting(): void
    {
        $main = $this->product('المنيوم سبير', 'MAINR', 'weight');
        $piece = $this->product('عليون', 'WR1', 'weight', $main->id, 1.5, 6);
        $service = app(SalesInvoiceService::class);

        $draft = $service->create($this->invoiceData($this->customer, [
            ['product_id' => $main->id, 'unit_price' => 5, 'pieces' => [['product_id' => $piece->id, 'piece_count' => 4]]],
        ]));

        $this->assertSame(0.0, (float) $draft->total_amount);

        $this->expectException(DomainException::class);

        $service->post($draft);
    }

    private function postPurchaseInvoiceFromOrder(PurchaseOrder $order): PurchaseInvoice
    {
        $service = app(PurchaseInvoiceService::class);

        return $service->post($service->createFromOrder($order));
    }

    private function postSalesInvoiceFromOrder(SalesOrder $order): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->post($service->createFromOrder($order));
    }

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

    private function warehouse(string $name, string $code): Warehouse
    {
        $warehouse = Warehouse::create(['code' => $code, 'is_active' => true]);
        $warehouse->translateOrNew('ar')->name = $name;
        $warehouse->save();

        return $warehouse;
    }

    private function product(string $name, string $barcode, string $pricingType = 'simple', ?int $mainId = null, float $weightPerMeter = 0, float $length = 0): Product
    {
        $product = new Product([
            'slug' => 'inv-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => $barcode,
            'base_price' => 10,
            'cost_price' => 8,
            'sale_price' => 20,
            'pricing_type' => $pricingType,
            'main_product_id' => $mainId,
            'weight_per_meter' => $weightPerMeter ?: null,
            'length' => $length ?: null,
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    private function service(string $name): Product
    {
        $product = $this->product($name, 'SRV-'.Str::random(4));
        $product->update(['item_type' => 'service', 'service_kind' => 'general', 'service_cost_source' => 'internal']);

        return $product;
    }

    /**
     * صنف رئيسي موزون (3 للكغ) بقطعتين: الاول 10 قطع × 6 م × 1.5 = 90 كغ، الثاني 20 × 6 × 0.75 = 90 كغ،
     * وقيمة البند 3000 (بوزن 1000 كغ).
     *
     * @return array{0: PurchaseOrder, 1: Product, 2: Product, 3: Product}
     */
    private function weightPurchaseOrder(): array
    {
        $main = $this->product('المنيوم سبير لون ميتالك 9007', 'MAIN1', 'weight');
        $subA = $this->product('عليون 3 مجاري K3317', 'BLT476', 'weight', $main->id, 1.5, 6);
        $subB = $this->product('حلق 4500 K3403', 'BLT352', 'weight', $main->id, 0.75, 6);

        $order = $this->purchaseOrder([]);
        $item = $order->items()->create([
            'product_id' => $main->id,
            'description' => $main->name,
            'quantity' => 1,
            'unit_price' => 3,
            'line_total' => 3000,
            'total_weight' => 1000,
            // مبلغ السطر بالوزن الفعلي؛ بدونه صفر فلا تُرحَّل الفاتورة.
            'actual_weight' => 1000,
        ]);
        $item->pieces()->create(['product_id' => $subA->id, 'description' => $subA->name, 'piece_count' => 10, 'piece_length' => 6, 'weight_per_meter' => 1.5, 'total_weight' => 90, 'sort_order' => 0]);
        $item->pieces()->create(['product_id' => $subB->id, 'description' => $subB->name, 'piece_count' => 20, 'piece_length' => 6, 'weight_per_meter' => 0.75, 'total_weight' => 90, 'sort_order' => 1]);
        $order->update(['sub_total' => 3000, 'total_amount' => 3000]);

        return [$order->refresh(), $subA, $subB, $main];
    }

    /**
     * @param  array<int, array{0: Product, 1: float, 2: float}>  $lines  الصنف، الكمية، السعر
     */
    private function purchaseOrder(array $lines): PurchaseOrder
    {
        $total = array_sum(array_map(fn (array $line) => $line[1] * $line[2], $lines));
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
                'unit_price' => $price,
                'line_total' => $quantity * $price,
            ]);
        }

        return $order->refresh();
    }

    /**
     * سند استلام مسودة: [رقم القطعة (او 0 لبند الكمية) => الكمية].
     */
    private function receive(PurchaseOrder $order, array $quantities): GoodsReceipt
    {
        $order->load('items.pieces');
        $item = $order->items->first();
        $items = [];

        foreach ($quantities as $index => $quantity) {
            $piece = $item->pieces->values()[$index] ?? null;
            $items[] = ['purchase_order_item_id' => $item->id, 'purchase_order_item_piece_id' => $piece?->id, 'quantity' => $quantity];
        }

        return app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'receipt_date' => now()->toDateString(),
            'items' => $items,
        ]);
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

    private function openingBalance(Product $product, float $quantity, float $unitCost): void
    {
        app(StockAdjustmentService::class)->post(app(StockAdjustmentService::class)->create([
            'type' => 'opening',
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]],
        ]));
    }

    private function warehouseAccount(): int
    {
        return (int) app(InventoryAccounts::class)->warehouseAccountId($this->warehouse->refresh());
    }

    private function supplierAccount(): int
    {
        return (int) Account::query()->where('user_id', $this->supplier->id)->value('id');
    }

    private function customerAccount(): int
    {
        return (int) Account::query()->where('user_id', $this->customer->id)->value('id');
    }

    private function account(string $code): int
    {
        app(InventoryAccounts::class)->ensureDefaults();

        return (int) Account::query()->where('code', $code)->value('id');
    }

    /**
     * رصيد الحساب بالعملة الاساسية من القيود المرحّلة (مدين − دائن).
     */
    private function balance(int $accountId): float
    {
        $lines = JournalLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($query) => $query->whereIn('status', [2, 3]))
            ->get(['debit_base', 'credit_base']);

        return round((float) $lines->sum('debit_base') - (float) $lines->sum('credit_base'), 4);
    }

    private function totalBalance(): float
    {
        $lines = JournalLine::query()->whereHas('journalEntry', fn ($query) => $query->whereIn('status', [2, 3]))->get(['debit_base', 'credit_base']);

        return round((float) $lines->sum('debit_base') - (float) $lines->sum('credit_base'), 4);
    }
}
