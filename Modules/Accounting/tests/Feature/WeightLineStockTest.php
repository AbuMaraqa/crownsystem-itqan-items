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
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\ProductCost;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Services\DeliveryNoteService;
use Modules\Accounting\Services\GoodsReceiptService;
use Modules\Accounting\Services\InventoryAccounts;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Services\PurchaseOrderService;
use Modules\Accounting\Services\PurchaseReturnService;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\SalesReturnService;
use Modules\Accounting\Services\StockAdjustmentService;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Accounting\Services\StockLevelService;
use Modules\Accounting\Services\StockTransferService;
use Modules\Accounting\tests\Feature\Concerns\PriceGroupFixtures;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\Product;
use Modules\Items\Support\ColourlessGroup;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * سطر الوزن في المستندات سطر مجموعة سعر (التصنيف × اللون) قطعه ألوان المقاطع: كل لون يحمل عدده
 * ونصيبه من الكغم الفعلي ومن قيمة السطر، ورصيد المجموعة مجموع ألوانها. كل الارقام محسوبة يدوياً.
 *
 * قطعة واحدة بطول 6 ووزن متر 1.267 = 7.602 كغم محسوب.
 */
class WeightLineStockTest extends TestCase
{
    use PriceGroupFixtures;
    use RefreshDatabase;

    private Currency $currency;

    private Warehouse $warehouse;

    private User $supplier;

    private User $customer;

    private CategoryFinish $group;

    private Product $piece;

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

        $this->warehouse = $this->makeWarehouse('المخزن الرئيسي', 'WH1');
        $category = Category::create(['slug' => 'alu-'.Str::lower(Str::random(6)), 'status' => 1, 'pricing_type' => 'weight']);
        $category->translateOrNew('ar')->name = 'المنيوم';
        $category->save();
        $this->group = CategoryFinish::create(['category_id' => $category->id, 'finish_id' => Finish::create(['name' => 'فضي', 'is_active' => true])->id, 'is_active' => true]);
        $this->piece = $this->colour('مقطع', 6, 1.267);
    }

    // ------------------------------------------------------------ invoices without an order

    public function test_buying_a_group_line_puts_the_actual_kg_and_value_on_the_group_and_only_the_count_on_the_colour(): void
    {
        $this->buy(actual: 8, price: 11);

        $this->assertMain(kg: 8, value: 88);
        $this->assertSame(1.0, $this->onHand($this->piece));
        $this->assertSame(7.602, $this->weightOf($this->piece));
        $this->assertSame(0.0, $this->valueOf($this->piece));

        $groupMovement = StockMovement::query()->where('category_finish_id', $this->group->id)->sole();
        $this->assertNull($groupMovement->product_id);
        $this->assertSame(8.0, (float) $groupMovement->quantity);
        $this->assertSame(11.0, (float) $groupMovement->unit_cost);
        $this->assertSame(88.0, (float) $groupMovement->total_cost);

        $pieceMovement = StockMovement::query()->where('product_id', $this->piece->id)->sole();
        $this->assertSame(1.0, (float) $pieceMovement->quantity);
        $this->assertSame(7.602, (float) $pieceMovement->weight);
        $this->assertSame(0.0, (float) $pieceMovement->total_cost);

        // من حـ/ المخزون 88 الى حـ/ المورد 88.
        $this->assertSame(88.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->totalBalance());
    }

    public function test_buying_with_an_empty_actual_stocks_the_colour_at_its_calculated_weight(): void
    {
        $this->buyWithAnotherLine(actual: null, price: 11);

        // بلا وزن فعلي: الكغم المحسوب 7.602 × 11 = 83.622.
        $this->assertSame(1.0, $this->onHand($this->piece));
        $this->assertSame(7.602, $this->weightOf($this->piece));
    }

    /**
     * مقطع لم يُضف له لون يدخل مجموعة «تصنيفه — بلا لون» (ColourlessGroup)، فيُشترى ويُباع كالمقاطع
     * الملوّنة: الكيلو والقيمة على المجموعة، والعدد على المقطع، والقيود متوازنة.
     */
    public function test_a_profile_without_a_colour_is_bought_and_sold_through_its_no_colour_group(): void
    {
        $brand = Category::create(['slug' => 'alb-'.Str::lower(Str::random(6)), 'status' => 1, 'pricing_type' => 'weight']);
        $brand->translateOrNew('ar')->name = 'الوبين';
        $brand->save();
        $profile = $this->product('سنة منخل CW60', ['pricing_type' => 'weight', 'base_price' => 0, 'length' => 6, 'weight_per_meter' => 0.156]);
        $profile->categories()->attach($brand->id);

        ColourlessGroup::assign();

        $profile->refresh();
        $this->group = CategoryFinish::query()->findOrFail($profile->category_finish_id);
        $this->piece = $profile;
        $this->assertSame((int) $brand->id, (int) $this->group->category_id);
        $this->assertTrue((bool) Finish::query()->find($this->group->finish_id)?->is_system);

        $this->buy(actual: 10, price: 20);
        $this->assertMain(kg: 10, value: 200);
        $this->assertSame(1.0, $this->onHand($profile));
        $this->assertSame(0.0, $this->valueOf($profile));

        $this->sell(actual: 4, price: 30);
        $this->assertMain(kg: 6, value: 120);
        $this->assertSame(0.0, $this->onHand($profile));
        $this->assertSame(120.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->totalBalance());
    }

    public function test_selling_an_actual_weight_takes_kg_and_cost_from_the_main_item(): void
    {
        $this->buy(actual: 8, price: 11);
        $this->sell(actual: 5, price: 20);

        // 8 − 5 = 3 كغم، والتكلفة 5 × 11 = 55، والباقي 3 × 11 = 33.
        $this->assertMain(kg: 3, value: 33);
        $this->assertSame(0.0, $this->onHand($this->piece));
        $this->assertSame(55.0, $this->balance($this->cogsAccount()));
        $this->assertSame(33.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->totalBalance());
    }

    public function test_selling_with_an_empty_actual_leaves_the_main_item_and_books_no_cost(): void
    {
        $this->buy(actual: 8, price: 11);

        // المستند بلا مبلغ يُرفض عند الترحيل، فمعه سطر خدمة بلا اثر على المخزون.
        $service = $this->product('خدمة', ['item_type' => 'service']);
        $sales = app(SalesInvoiceService::class);
        $sales->post($sales->create($this->invoiceData($this->customer, [
            $this->weightLine(null, 20),
            ['product_id' => $service->id, 'quantity' => 1, 'unit_price' => 10],
        ])));

        $this->assertMain(kg: 8, value: 88);
        $this->assertSame(0.0, $this->onHand($this->piece));
        $this->assertSame(0.0, $this->balance($this->cogsAccount()));
        $this->assertSame(0.0, $this->totalBalance());
    }

    public function test_selling_more_kg_than_the_main_item_holds_is_refused(): void
    {
        $this->buy(actual: 8, price: 11);

        $this->expectException(DomainException::class);

        $this->sell(actual: 9, price: 20);
    }

    public function test_the_availability_check_passes_with_the_negative_stock_permission_only(): void
    {
        $line = [['product_id' => $this->piece->id, 'location_id' => null, 'quantity' => 5]];

        try {
            app(StockLevelService::class)->assertAvailable($this->warehouse->id, $line);
            $this->fail('A shortage without the permission must be refused.');
        } catch (DomainException) {
        }

        $this->allowNegativeStock();
        app(StockLevelService::class)->assertAvailable($this->warehouse->id, $line);
        $this->addToAssertionCount(1);
    }

    public function test_selling_more_pieces_than_on_hand_is_refused(): void
    {
        $this->buy(actual: 8, price: 11);

        $this->expectException(DomainException::class);

        $sales = app(SalesInvoiceService::class);
        $sales->post($sales->create($this->invoiceData($this->customer, [$this->weightLine(1, 20, count: 2)])));
    }

    public function test_the_invoice_discount_stays_on_the_main_value(): void
    {
        $purchase = app(PurchaseInvoiceService::class);
        $purchase->post($purchase->create($this->invoiceData($this->supplier, [$this->weightLine(8, 11)], ['invoice_discount' => 8])));

        // 88 − 8 = 80 ولا شيء منها على المقطع.
        $this->assertMain(kg: 8, value: 80);
        $this->assertSame(0.0, $this->valueOf($this->piece));
    }

    // ------------------------------------------------------------ returns and cancellation

    public function test_cancelling_a_sale_restores_exactly(): void
    {
        $this->buy(actual: 8, price: 11);
        $invoice = $this->sell(actual: 5, price: 20);

        app(SalesInvoiceService::class)->cancel($invoice, 'test');

        $this->assertMain(kg: 8, value: 88);
        $this->assertSame(1.0, $this->onHand($this->piece));
        $this->assertSame(0.0, $this->balance($this->cogsAccount()));
        $this->assertSame(88.0, $this->balance($this->warehouseAccount()));
    }

    public function test_cancelling_a_purchase_restores_exactly(): void
    {
        $invoice = $this->buy(actual: 8, price: 11);

        app(PurchaseInvoiceService::class)->cancel($invoice, 'test');

        $this->assertMain(kg: 0, value: 0);
        $this->assertSame(0.0, $this->onHand($this->piece));
        $this->assertSame(0.0, $this->balance($this->warehouseAccount()));
    }

    public function test_a_sales_return_puts_back_kg_and_cost_of_the_original(): void
    {
        $this->buy(actual: 8, price: 11);
        $invoice = $this->sell(actual: 5, price: 20);

        $service = app(SalesReturnService::class);
        $return = $service->post($service->create($this->salesReturnData($invoice)));

        // رجع الوزن كله: 5 كغم بكلفة خروجها 55.
        $this->assertMain(kg: 8, value: 88);
        $this->assertSame(1.0, $this->onHand($this->piece));
        $this->assertSame(0.0, $this->balance($this->cogsAccount()));
        $this->assertSame(0.0, $this->totalBalance());

        $service->cancel($return, 'test');

        $this->assertMain(kg: 3, value: 33);
        $this->assertSame(0.0, $this->onHand($this->piece));
        $this->assertSame(55.0, $this->balance($this->cogsAccount()));
    }

    public function test_a_purchase_return_takes_out_kg_value_and_count(): void
    {
        $invoice = $this->buy(actual: 8, price: 11);

        $service = app(PurchaseReturnService::class);
        $return = $service->post($service->create($this->purchaseReturnData($invoice)));

        $this->assertMain(kg: 0, value: 0);
        $this->assertSame(0.0, $this->onHand($this->piece));
        $this->assertSame(0.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->totalBalance());

        $service->cancel($return, 'test');

        $this->assertMain(kg: 8, value: 88);
        $this->assertSame(1.0, $this->onHand($this->piece));
        $this->assertSame(88.0, $this->balance($this->warehouseAccount()));
    }

    public function test_returning_a_line_with_an_empty_actual_moves_only_the_pieces(): void
    {
        $service = $this->product('خدمة شراء', ['item_type' => 'service']);
        $purchase = app(PurchaseInvoiceService::class);
        $invoice = $purchase->post($purchase->create($this->invoiceData($this->supplier, [
            $this->weightLine(null, 11),
            ['product_id' => $service->id, 'quantity' => 1, 'unit_price' => 10],
        ])));
        $line = $invoice->items()->with('pieces')->get()->first(fn ($item) => $item->pieces->isNotEmpty());

        $returns = app(PurchaseReturnService::class);
        $serviceLine = $invoice->items()->where('product_id', $service->id)->sole();
        $returns->post($returns->create([
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'items' => [
                ['purchase_invoice_item_id' => $line->id, 'pieces' => [['purchase_invoice_item_piece_id' => $line->pieces->first()->id, 'piece_count' => 1]]],
                ['purchase_invoice_item_id' => $serviceLine->id, 'quantity' => 1],
            ],
        ]));

        $this->assertMain(kg: 0, value: 0);
        $this->assertSame(0.0, $this->onHand($this->piece));
    }

    // ------------------------------------------------------------ order flows

    public function test_a_receipt_with_calculated_weight_then_an_invoice_with_another_actual_ends_on_the_invoice_actual(): void
    {
        $order = $this->purchaseOrder(price: 11);
        $this->postReceipt($order);

        // بلا وزن فعلي: الوزن المحسوب 7.602 بقيمة 7.602 × 11 = 83.622، والمقطع بلا قيمة.
        $this->assertMain(kg: 7.602, value: 83.622);
        $this->assertSame(1.0, $this->onHand($this->piece));
        $this->assertSame(83.622, $this->balance($this->warehouseAccount()));
        $this->assertSame(-83.622, $this->balance($this->grniAccount()));

        $invoice = $this->invoiceOrder($order, actual: 8, price: 11);

        // التسوية +0.398 كغم بقيمة 4.378 حتى 8 كغم و88.
        $this->assertMain(kg: 8, value: 88);
        $this->assertSame(1.0, $this->onHand($this->piece));
        $this->assertSame(88.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->balance($this->grniAccount()));
        $this->assertSame(0.0, $this->balance($this->varianceAccount()));
        $this->assertSame(-88.0, $this->balance($this->supplierAccount()));
        $this->assertSame(0.0, $this->totalBalance());

        // الغاء الفاتورة يعيد ما قبلها كما كان.
        app(PurchaseInvoiceService::class)->cancel($invoice, 'test');

        $this->assertMain(kg: 7.602, value: 83.622);
        $this->assertSame(83.622, $this->balance($this->warehouseAccount()));
        $this->assertSame(-83.622, $this->balance($this->grniAccount()));
        $this->assertSame(0.0, $this->balance($this->varianceAccount()));
        $this->assertSame(0.0, $this->totalBalance());
    }

    public function test_an_invoice_with_less_actual_than_received_takes_the_difference_out(): void
    {
        $order = $this->purchaseOrder(price: 11, actual: 8);
        $this->postReceipt($order);
        $this->assertMain(kg: 8, value: 88);

        $invoice = $this->invoiceOrder($order, actual: 7, price: 11);

        // 7 × 11 = 77.
        $this->assertMain(kg: 7, value: 77);
        $this->assertSame(77.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->balance($this->grniAccount()));
        $this->assertSame(0.0, $this->balance($this->varianceAccount()));
        $this->assertSame(0.0, $this->totalBalance());

        app(PurchaseInvoiceService::class)->cancel($invoice, 'test');

        $this->assertMain(kg: 8, value: 88);
        $this->assertSame(0.0, $this->totalBalance());
    }

    public function test_an_invoice_at_another_price_revalues_the_received_kg(): void
    {
        // طلبية بلا سعر ولا تكلفة: دخل 7.602 كغم بقيمة صفر.
        $order = $this->purchaseOrder(price: 0);
        $this->postReceipt($order);
        $this->assertMain(kg: 7.602, value: 0);

        $this->invoiceOrder($order, actual: 8, price: 11);

        $this->assertMain(kg: 8, value: 88);
        $this->assertSame(88.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->balance($this->varianceAccount()));
        $this->assertSame(0.0, $this->totalBalance());
    }

    /* ---------- سطر مجموعة السعر (المرحلة 2): كل لون يدخل بقطعه ونصيبه من الوزن والقيمة ---------- */

    public function test_a_revalued_receipt_sells_at_the_invoice_price(): void
    {
        $order = $this->purchaseOrder(price: 0);
        $this->postReceipt($order);
        $this->invoiceOrder($order, actual: 8, price: 11);

        $this->sell(actual: 5, price: 20);

        // طبقة الاستلام 7.602 كغم صار سعرها 11: التكلفة 5 × 11 = 55.
        $this->assertSame(55.0, $this->balance($this->cogsAccount()));
        $this->assertMain(kg: 3, value: 33);
        $this->assertSame(0.0, $this->totalBalance());
    }

    public function test_fifo_applies_to_main_items_across_two_purchases(): void
    {
        $this->buy(actual: 5, price: 10);
        $this->buy(actual: 5, price: 12);

        // بيع قطعة بوزن فعلي 7: FIFO 5 × 10 + 2 × 12 = 74.
        $invoice = $this->sell(actual: 7, price: 20);

        $this->assertSame(74.0, $this->balance($this->cogsAccount()));
        // الباقي 3 × 12 = 36.
        $this->assertMain(kg: 3, value: 36);
        $this->assertSame(36.0, $this->balance($this->warehouseAccount()));

        app(SalesInvoiceService::class)->cancel($invoice, 'test');

        $this->assertMain(kg: 10, value: 110);
        $this->assertSame(0.0, $this->balance($this->cogsAccount()));
    }

    public function test_an_invoice_with_no_earlier_receipt_receives_and_trues_up(): void
    {
        $order = $this->purchaseOrder(price: 11);

        $this->invoiceOrder($order, actual: 8, price: 11);

        $this->assertMain(kg: 8, value: 88);
        $this->assertSame(1.0, $this->onHand($this->piece));
        $this->assertSame(0.0, $this->balance($this->grniAccount()));
        $this->assertSame(0.0, $this->totalBalance());
    }

    public function test_a_partial_receipt_takes_the_actual_pro_rata_and_the_last_takes_the_rest(): void
    {
        $short = $this->colour('مقطع قصير', 2, 1);

        // المحسوب: 7.602 + 2 = 9.602، الفعلي 10، السعر 11، القيمة 110.
        $order = app(PurchaseOrderService::class)->confirm(app(PurchaseOrderService::class)->create([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => null,
                'category_finish_id' => $this->group->id,
                'quantity' => 1,
                'unit_price' => 11,
                'actual_weight' => 10,
                'pieces' => [
                    ['product_id' => $this->piece->id, 'piece_count' => 1, 'piece_length' => 6, 'weight_per_meter' => 1.267],
                    ['product_id' => $short->id, 'piece_count' => 1, 'piece_length' => 2, 'weight_per_meter' => 1],
                ],
            ]],
        ]));
        $item = $order->items()->with('pieces')->first();
        $receipts = app(GoodsReceiptService::class);

        $receipts->post($receipts->create([
            'purchase_order_id' => $order->id,
            'items' => [['purchase_order_item_id' => $item->id, 'purchase_order_item_piece_id' => $item->pieces[0]->id, 'quantity' => 1]],
        ]));

        // 10 × 7.602 ÷ 9.602 = 7.9171، و110 × 7.602 ÷ 9.602 = 87.0881.
        $this->assertMain(kg: 7.9171, value: 87.0881);
        $this->assertSame(1.0, $this->onHand($this->piece));
        $this->assertSame(0.0, $this->onHand($short));

        $receipts->post($receipts->create([
            'purchase_order_id' => $order->id,
            'items' => [['purchase_order_item_id' => $item->id, 'purchase_order_item_piece_id' => $item->pieces[1]->id, 'quantity' => 1]],
        ]));

        // الباقي يكمل الى 10 كغم و110 بلا فرق تقريب.
        $this->assertMain(kg: 10, value: 110);
        $this->assertSame(1.0, $this->onHand($short));
        $this->assertSame(110.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(-110.0, $this->balance($this->grniAccount()));
    }

    public function test_cancelling_a_receipt_restores_exactly(): void
    {
        $order = $this->purchaseOrder(price: 11, actual: 8);
        $receipt = $this->postReceipt($order);

        app(GoodsReceiptService::class)->cancel($receipt);

        $this->assertMain(kg: 0, value: 0);
        $this->assertSame(0.0, $this->onHand($this->piece));
        $this->assertSame(0.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->balance($this->grniAccount()));
    }

    public function test_a_delivery_then_an_invoice_with_another_actual_ends_on_the_invoice_actual(): void
    {
        $this->buy(actual: 8, price: 11);

        $order = $this->salesOrder();
        $item = $order->items()->with('pieces')->first();
        $notes = app(DeliveryNoteService::class);
        $note = $notes->post($notes->create([
            'sales_order_id' => $order->id,
            'items' => [['sales_order_item_id' => $item->id, 'sales_order_item_piece_id' => $item->pieces->first()->id, 'quantity' => 1]],
        ]));

        // بلا وزن فعلي: 7.602 كغم بتكلفة 7.602 × 11 = 83.622، والباقي 0.398 × 11 = 4.378.
        $this->assertMain(kg: 0.398, value: 4.378);
        $this->assertSame(0.0, $this->onHand($this->piece));
        $this->assertSame(83.622, (float) $note->total_cost);
        $this->assertSame(83.622, $this->balance($this->cogsAccount()));

        // 8 كغم مفوترة اكبر من 7.602 المسلَّمة: تخرج 0.398 الباقية بتكلفتها 4.378.
        $this->invoiceSalesOrder($order, actual: 8, price: 20);

        $this->assertMain(kg: 0, value: 0);
        $this->assertSame(88.0, $this->balance($this->cogsAccount()));
        $this->assertSame(0.0, $this->totalBalance());
    }

    public function test_an_invoice_on_a_delivered_order_with_less_actual_puts_the_difference_back(): void
    {
        $this->buy(actual: 8, price: 11);

        $order = $this->salesOrder();
        $item = $order->items()->with('pieces')->first();
        $notes = app(DeliveryNoteService::class);
        $notes->post($notes->create([
            'sales_order_id' => $order->id,
            'items' => [['sales_order_item_id' => $item->id, 'sales_order_item_piece_id' => $item->pieces->first()->id, 'quantity' => 1]],
        ]));

        $invoice = $this->invoiceSalesOrder($order, actual: 5, price: 20);

        // بيع 5 كغم فعلياً: الباقي 3 كغم و33، والتكلفة 55.
        $this->assertMain(kg: 3, value: 33);
        $this->assertSame(55.0, $this->balance($this->cogsAccount()));
        $this->assertSame(33.0, $this->balance($this->warehouseAccount()));
        $this->assertSame(0.0, $this->totalBalance());

        app(SalesInvoiceService::class)->cancel($invoice, 'test');

        // الغاء الفاتورة يلغي تسويتها فقط؛ سند التسليم اليدوي يبقى.
        $this->assertMain(kg: 0.398, value: 4.378);
        $this->assertSame(83.622, $this->balance($this->cogsAccount()));
        $this->assertSame(0.0, $this->totalBalance());
    }

    public function test_a_sale_from_an_order_without_a_note_issues_kg_of_the_invoice(): void
    {
        $this->buy(actual: 8, price: 11);

        $order = $this->salesOrder();
        $invoice = $this->invoiceSalesOrder($order, actual: 5, price: 20);

        $this->assertMain(kg: 3, value: 33);
        $this->assertSame(0.0, $this->onHand($this->piece));
        $this->assertSame(55.0, $this->balance($this->cogsAccount()));

        app(SalesInvoiceService::class)->cancel($invoice, 'test');

        $this->assertMain(kg: 8, value: 88);
        $this->assertSame(1.0, $this->onHand($this->piece));
        $this->assertSame(0.0, $this->balance($this->cogsAccount()));
    }

    // ------------------------------------------------------------ transfers and adjustments

    // ------------------------------------------------------------ readers

    // ------------------------------------------------------------ helpers

    private function buy(?float $actual, float $price): PurchaseInvoice
    {
        $service = app(PurchaseInvoiceService::class);

        return $service->post($service->create($this->invoiceData($this->supplier, [$this->weightLine($actual, $price)])));
    }

    private function buyWithAnotherLine(?float $actual, float $price): PurchaseInvoice
    {
        $other = $this->product('خدمة شراء', ['item_type' => 'service']);
        $service = app(PurchaseInvoiceService::class);

        return $service->post($service->create($this->invoiceData($this->supplier, [
            $this->weightLine($actual, $price),
            ['product_id' => $other->id, 'quantity' => 1, 'unit_price' => 10],
        ])));
    }

    private function sell(?float $actual, float $price): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->post($service->create($this->invoiceData($this->customer, [$this->weightLine($actual, $price)])));
    }

    private function weightLine(?float $actual, float $price, float $count = 1): array
    {
        return [
            'product_id' => null,
                'category_finish_id' => $this->group->id,
            'quantity' => $actual ?? 0,
            'actual_weight' => $actual,
            'unit_price' => $price,
            'pieces' => [['product_id' => $this->piece->id, 'piece_count' => $count, 'piece_length' => 6, 'weight_per_meter' => 1.267]],
        ];
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

    private function salesReturnData(SalesInvoice $invoice): array
    {
        $line = $invoice->items()->with('pieces')->firstOrFail();

        return [
            'sales_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'items' => [[
                'sales_invoice_item_id' => $line->id,
                'pieces' => [['sales_invoice_item_piece_id' => $line->pieces->first()->id, 'piece_count' => 1]],
            ]],
        ];
    }

    private function purchaseReturnData(PurchaseInvoice $invoice): array
    {
        $line = $invoice->items()->with('pieces')->firstOrFail();

        return [
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'items' => [[
                'purchase_invoice_item_id' => $line->id,
                'pieces' => [['purchase_invoice_item_piece_id' => $line->pieces->first()->id, 'piece_count' => 1]],
            ]],
        ];
    }

    private function purchaseOrder(float $price, ?float $actual = null): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);

        return $service->confirm($service->create([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => null,
                'category_finish_id' => $this->group->id,
                'quantity' => 1,
                'unit_price' => $price,
                'actual_weight' => $actual,
                'pieces' => [['product_id' => $this->piece->id, 'piece_count' => 1, 'piece_length' => 6, 'weight_per_meter' => 1.267]],
            ]],
        ]));
    }

    private function postReceipt(PurchaseOrder $order)
    {
        $item = $order->items()->with('pieces')->first();
        $service = app(GoodsReceiptService::class);

        return $service->post($service->create([
            'purchase_order_id' => $order->id,
            'items' => [['purchase_order_item_id' => $item->id, 'purchase_order_item_piece_id' => $item->pieces->first()->id, 'quantity' => 1]],
        ]));
    }

    private function invoiceOrder(PurchaseOrder $order, ?float $actual, float $price): PurchaseInvoice
    {
        $service = app(PurchaseInvoiceService::class);
        $order = PurchaseOrder::query()->with(['items.pieces', 'items.product'])->findOrFail($order->id);
        $item = $order->items->first();

        return $service->post($service->create($this->invoiceData($this->supplier, [[
            'purchase_order_item_id' => $item->id,
            'product_id' => null,
            'category_finish_id' => $item->category_finish_id,
            'quantity' => $actual ?? 0,
            'actual_weight' => $actual,
            'unit_price' => $price,
            'pieces' => PurchaseInvoiceService::orderPieces($item),
        ]], ['purchase_order_id' => $order->id])));
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>  $pieces
     */
    private function groupPurchaseOrder(CategoryFinish $group, array $pieces, float $actual, float $price): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);
        $line = $this->groupLine($group, $pieces, $actual, $price);

        return $service->confirm($service->create([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [$line],
        ]));
    }

    /**
     * فاتورة شراء بلا طلبية بسطر مجموعة سعر، مرحّلة.
     *
     * @param  array<int, array{0: Product, 1: int}>  $pieces
     */
    private function buyGroup(CategoryFinish $group, array $pieces, float $actual, float $price): PurchaseInvoice
    {
        $service = app(PurchaseInvoiceService::class);

        return $service->post($service->create($this->invoiceData($this->supplier, [$this->groupLine($group, $pieces, $actual, $price)])));
    }

    private function postGroupReceipt(PurchaseOrder $order)
    {
        $item = $order->items()->with('pieces')->sole();
        $service = app(GoodsReceiptService::class);

        return $service->post($service->create([
            'purchase_order_id' => $order->id,
            'items' => $item->pieces->map(fn ($piece) => [
                'purchase_order_item_id' => $item->id,
                'purchase_order_item_piece_id' => $piece->id,
                'quantity' => (float) $piece->piece_count,
            ])->all(),
        ]));
    }

    private function invoiceGroupOrder(PurchaseOrder $order, float $actual, float $price): PurchaseInvoice
    {
        $service = app(PurchaseInvoiceService::class);
        $item = PurchaseOrder::query()->with('items.pieces')->findOrFail($order->id)->items->sole();

        return $service->post($service->create($this->invoiceData($this->supplier, [[
            'purchase_order_item_id' => $item->id,
            'product_id' => null,
            'category_finish_id' => $item->category_finish_id,
            'quantity' => $actual,
            'actual_weight' => $actual,
            'unit_price' => $price,
            'pieces' => PurchaseInvoiceService::orderPieces($item),
        ]], ['purchase_order_id' => $order->id])));
    }

    private function salesOrder(): SalesOrder
    {
        // طلبية بيع بلا وزن فعلي مبلغها صفر فلا تؤكَّد من الخدمة؛ تُنشأ مؤكدة مباشرة.
        $order = SalesOrder::create([
            'reference_no' => 'SO-'.Str::random(6),
            'client_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'exchange_rate' => 1,
            // طلبية بمبلغ صفر لا تُفوتر؛ هذا مبلغها التقديري.
            'sub_total' => 152.04,
            'total_amount' => 152.04,
            'status' => SalesOrderStatus::CONFIRMED->value(),
        ]);
        $item = $order->items()->create(['product_id' => null, 'category_finish_id' => $this->group->id, 'description' => 'المنيوم — أبيض', 'quantity' => 1, 'unit_price' => 20, 'total_weight' => 7.602]);
        $item->pieces()->create(['product_id' => $this->piece->id, 'description' => $this->piece->name, 'piece_count' => 1, 'piece_length' => 6, 'weight_per_meter' => 1.267, 'total_weight' => 7.602, 'sort_order' => 0]);

        return $order->refresh();
    }

    private function invoiceSalesOrder(SalesOrder $order, ?float $actual, float $price): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);
        $order = SalesOrder::query()->with(['items.pieces', 'items.product'])->findOrFail($order->id);
        $item = $order->items->first();

        return $service->post($service->create($this->invoiceData($this->customer, [[
            'sales_order_item_id' => $item->id,
            'product_id' => null,
            'category_finish_id' => $item->category_finish_id,
            'quantity' => $actual ?? 0,
            'actual_weight' => $actual,
            'unit_price' => $price,
            'pieces' => SalesInvoiceService::orderPieces($item),
        ]], ['sales_order_id' => $order->id])));
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        $warehouse = Warehouse::create(['code' => $code, 'is_active' => true]);
        $warehouse->translateOrNew('ar')->name = $name;
        $warehouse->save();

        return $warehouse;
    }

    private function product(string $name, array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'slug' => 'wm-'.Str::lower(Str::random(8)),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => 'WM-'.Str::random(6),
            'base_price' => 10,
            'pricing_type' => 'simple',
        ], $attributes));
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    private function allowNegativeStock(): void
    {
        Permission::firstOrCreate(['name' => StockLedgerService::NEGATIVE_STOCK_PERMISSION, 'guard_name' => 'web']);
        auth()->user()->givePermissionTo(StockLedgerService::NEGATIVE_STOCK_PERMISSION);
        auth()->user()->refresh();
    }

    /**
     * رصيد المجموعة: مجموع كغم ألوانها وقيمتها.
     */
    private function assertMain(float $kg, float $value): void
    {
        $group = app(StockLevelService::class)->groupLevels([$this->group->id])[$this->group->id] ?? ['weight' => 0.0, 'value' => 0.0];

        $this->assertEqualsWithDelta($kg, $group['weight'], 0.00001, 'group weight');
        $this->assertEqualsWithDelta($value, $group['value'], 0.00001, 'group value');
    }

    /**
     * لون مقطع في المجموعة بقياس قطعته.
     */
    private function colour(string $name, float $length, float $weightPerMeter): Product
    {
        return $this->product($name, ['pricing_type' => 'weight', 'base_price' => 0, 'category_finish_id' => $this->group->id, 'finish_id' => $this->group->finish_id, 'length' => $length, 'weight_per_meter' => $weightPerMeter]);
    }

    private function onHand(Product $product, ?int $warehouseId = null): float
    {
        return round((float) StockBalance::query()->where('product_id', $product->id)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->sum('quantity'), 4);
    }

    private function weightOf(Product $product, ?int $warehouseId = null): float
    {
        return round((float) StockBalance::query()->where('product_id', $product->id)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->sum('weight'), 4);
    }

    private function valueOf(Product $product): float
    {
        return round((float) ProductCost::query()->where('product_id', $product->id)->value('value'), 4);
    }

    private function warehouseAccount(): int
    {
        return app(InventoryAccounts::class)->warehouseAccountId($this->warehouse->refresh());
    }

    private function grniAccount(): int
    {
        return app(InventoryAccounts::class)->mapped(InventoryAccounts::GRNI_KEY);
    }

    private function varianceAccount(): int
    {
        return app(InventoryAccounts::class)->mapped(InventoryAccounts::PRICE_VARIANCE_KEY);
    }

    private function cogsAccount(): int
    {
        return app(InventoryAccounts::class)->mapped(InventoryAccounts::COGS_KEY);
    }

    private function supplierAccount(): int
    {
        return (int) Account::query()->where('user_id', $this->supplier->id)->value('id');
    }

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
