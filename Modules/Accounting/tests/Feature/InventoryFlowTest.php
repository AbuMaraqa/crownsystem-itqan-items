<?php

namespace Modules\Accounting\tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\ProductLocation;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Entities\WarehouseLocation;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Services\DeliveryNoteService;
use Modules\Accounting\Services\GoodsReceiptService;
use Modules\Accounting\Services\InventoryAccounts;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Services\StockAdjustmentService;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Accounting\Services\StockLevelService;
use Modules\Accounting\Services\StockTransferService;
use Modules\Accounting\Services\WarehouseLayoutService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * دورة المخزن كاملة: استلام من طلبية شراء ← فاتورة الشراء ← حجز بطلبية بيع ← تسليم،
 * مع القيود في شجرة الحسابات، والشحن المباشر للزبون، والتحويل، والجرد، ومواقع الرفوف.
 */
class InventoryFlowTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Warehouse $warehouse;

    private User $supplier;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->supplier = User::factory()->create(['name' => 'مورد الصين']);
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

    public function test_the_items_grid_shows_a_sub_items_stock_and_incoming_purchase_orders(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        [$order, $subA] = $this->weightPurchaseOrder();

        foreach (['Sales Order Create', 'Stock View List'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo('Sales Order Create');
        $this->actingAs($user);
        $url = route('accounting.items-grid.details', ['document' => 'sales-order', 'product_id' => $subA->id]);

        // من لا يرى المخزون لا يفتح التفاصيل.
        $this->getJson($url)->assertForbidden();

        $user->givePermissionTo('Stock View List');
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('product.barcode', 'BLT476')
            ->assertJsonPath('card.levels.on_hand', '0')
            ->assertJsonPath('card.levels.incoming', '10')
            ->assertJsonPath('card.purchase_orders.0.reference', $order->reference_no)
            ->assertJsonPath('card.purchase_orders.0.party', 'مورد الصين')
            ->assertJsonPath('card.purchase_orders.0.quantity', '10')
            ->assertJsonPath('card.sales_orders', []);
    }

    public function test_receiving_a_weight_order_puts_sub_items_in_stock_and_posts_to_grni(): void
    {
        [$order, $subA, $subB] = $this->weightPurchaseOrder();

        $levels = app(StockLevelService::class)->levels([$subA->id, $subB->id], $this->warehouse->id);
        $this->assertSame(10.0, $levels[$subA->id]['incoming']);
        $this->assertSame(20.0, $levels[$subB->id]['incoming']);

        // استلام جزئي: 4 قطع من الاول فقط.
        $receipt = $this->receive($order, ['piece:A' => 4]);
        app(GoodsReceiptService::class)->post($receipt);

        $ledger = app(StockLedgerService::class);
        $this->assertSame(4.0, $ledger->onHand($subA->id, $this->warehouse->id));
        // المقطع بلا كلفة. الرئيسي: 4 قطع × 9 كغم = 36 كغم من 180، وقيمتها 3000 × 36 ÷ 180 = 600.
        $this->assertSame(0.0, $ledger->averageCost($subA->id));
        $this->assertSame(36.0, $ledger->onHand($subA->main_product_id, $this->warehouse->id));
        $this->assertSame(16.666667, $ledger->averageCost($subA->main_product_id));
        $this->assertSame(4.0, (float) Product::find($subA->id)->stock_quantity);
        $this->assertSame(PurchaseOrderStatus::CONFIRMED, $order->refresh()->status);

        $levels = app(StockLevelService::class)->levels([$subA->id], $this->warehouse->id);
        $this->assertSame(6.0, $levels[$subA->id]['incoming']);
        $this->assertSame(36.0, $levels[$subA->id]['weight']);

        $warehouseAccount = $this->warehouse->refresh()->inventory_account_id;
        $this->assertNotNull($warehouseAccount);
        $this->assertSame('1140', Account::find($warehouseAccount)->parent->code);
        $this->assertSame(600.0, $this->balance($warehouseAccount));
        $this->assertSame(-600.0, $this->balance($this->account('2125')));

        // الباقي يكمل الطلبية فتصبح «مستلمة».
        app(GoodsReceiptService::class)->post($this->receive($order, ['piece:A' => 6, 'piece:B' => 20]));
        $this->assertSame(PurchaseOrderStatus::RECEIVED, $order->refresh()->status);
        $this->assertSame(3000.0, $this->balance($warehouseAccount));
    }

    public function test_main_item_holds_its_own_kg_and_value_and_counts_its_sub_items(): void
    {
        [$order, $subA, $subB] = $this->weightPurchaseOrder();
        $mainId = $subA->main_product_id;
        $levels = app(StockLevelService::class);

        $this->assertSame(30.0, $levels->mainItemLevels([$mainId], $this->warehouse->id)[$mainId]['incoming']);

        app(GoodsReceiptService::class)->post($this->receive($order, ['piece:A' => 10, 'piece:B' => 20]));

        $main = $levels->mainItemLevels([$mainId, $subA->id], $this->warehouse->id);
        // الصنف الفرعي ليس رئيسياً فلا يظهر؛ الرئيسي: 30 قطعة من مقاطعه، و180 كغ (بلا وزن فعلي
        // يأخذ المحسوب) بقيمة 3000 في صفّه هو.
        $this->assertSame([$mainId], array_keys($main));
        $this->assertSame(30.0, $main[$mainId]['on_hand']);
        $this->assertSame(180.0, $main[$mainId]['weight']);
        $this->assertSame(3000.0, $main[$mainId]['value']);
        $this->assertSame(0.0, $main[$mainId]['incoming']);
        $this->assertSame(2, $main[$mainId]['sub_items']);

        // للرئيسي رصيده بالكغم، والوزن المحسوب على الفرعي للمعلومة.
        $this->assertSame(180.0, app(StockLedgerService::class)->onHand($mainId, $this->warehouse->id));
        $this->assertSame(90.0, (float) Product::find($subA->id)->stock_weight);
        $this->assertSame(90.0, (float) Product::find($subB->id)->stock_weight);

        $this->assertSame([$mainId], Product::query()->mainItems()->whereIn('id', [$mainId, $subA->id, $subB->id])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$subA->id, $subB->id], Product::query()->withoutMainItems()->whereIn('id', [$mainId, $subA->id, $subB->id])->pluck('id')->all());
    }

    public function test_purchase_invoice_clears_grni_and_books_price_variance(): void
    {
        [$order] = $this->weightPurchaseOrder();
        app(GoodsReceiptService::class)->post($this->receive($order, ['piece:A' => 10, 'piece:B' => 20]));

        $invoice = app(PurchaseInvoiceService::class)->createFromOrder($order);
        $invoice->items->first()->update(['quantity' => 180, 'actual_weight' => 180, 'unit_price' => 17.777778, 'line_total' => 3200]);
        $invoice->update(['sub_total' => 3200, 'total_amount' => 3200]);
        app(PurchaseInvoiceService::class)->post($invoice->refresh());

        // نفس الكغم 180 بمبلغ 3200 بدل 3000: الفرق 200 يعيد تقييم الصنف الرئيسي، فلا يبقى فرق سعر.
        $this->assertSame(0.0, $this->balance($this->account('2125')));
        $this->assertSame(0.0, $this->balance($this->account('5115')));
        $this->assertSame(0.0, $this->balance($this->account('5110')));
        $this->assertSame(3200.0, $this->balance($this->warehouse->refresh()->inventory_account_id));
    }

    public function test_delivery_issues_from_primary_location_first_and_books_cogs(): void
    {
        $product = $this->product('مسكة', 'BLT359');
        [$primary, $overflow] = $this->locations(['E10', 'E11']);
        app(WarehouseLayoutService::class)->assign($product->id, $primary);

        $this->openingBalance($product, [[5, $primary->id, 10], [3, $overflow->id, 20]]);
        $this->assertSame(13.75, app(StockLedgerService::class)->averageCost($product->id));

        $order = $this->salesOrder([[$product, 6]]);
        $this->assertSame(6.0, app(StockLevelService::class)->levels([$product->id])[$product->id]['reserved']);

        $note = app(DeliveryNoteService::class)->create([
            'sales_order_id' => $order->id,
            'items' => [['sales_order_item_id' => $order->items->first()->id, 'quantity' => 6]],
        ]);
        $note = app(DeliveryNoteService::class)->post($note);

        $picked = $note->items->first()->picked_locations;
        $this->assertSame([['E10', 5.0], ['E11', 1.0]], array_map(fn ($row) => [$row['code'], (float) $row['quantity']], $picked));
        // FIFO: 5 × 10 + 1 × 20 = 70.
        $this->assertSame(70.0, (float) $note->total_cost);
        $this->assertSame(70.0, $this->balance($this->account('5100')));

        $levels = app(StockLevelService::class)->levels([$product->id])[$product->id];
        $this->assertSame(2.0, $levels['on_hand']);
        $this->assertSame(0.0, $levels['reserved']);
    }

    public function test_delivery_is_blocked_when_stock_is_insufficient(): void
    {
        $product = $this->product('بروفيل', 'BLT1');
        $this->openingBalance($product, [[2, null, 10]]);
        $order = $this->salesOrder([[$product, 5]]);

        $note = app(DeliveryNoteService::class)->create([
            'sales_order_id' => $order->id,
            'items' => [['sales_order_item_id' => $order->items->first()->id, 'quantity' => 5]],
        ]);

        try {
            app(DeliveryNoteService::class)->post($note);
            $this->fail('Delivery should be blocked.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('BLT1', $exception->getMessage());
        }

        $this->assertSame(2.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertSame(InventoryDocumentStatus::DRAFT, $note->refresh()->status);
    }

    public function test_cancelling_a_receipt_is_blocked_once_its_stock_was_delivered(): void
    {
        $product = $this->product('زاوية', 'BLT2');
        $order = $this->quantityPurchaseOrder($product, 4, 25);
        $receipt = app(GoodsReceiptService::class)->post($this->receive($order, ['item' => 4]));

        $sales = $this->salesOrder([[$product, 3]]);
        $note = app(DeliveryNoteService::class)->post(app(DeliveryNoteService::class)->create([
            'sales_order_id' => $sales->id,
            'items' => [['sales_order_item_id' => $sales->items->first()->id, 'quantity' => 3]],
        ]));

        $this->expectException(DomainException::class);

        try {
            app(GoodsReceiptService::class)->cancel($receipt);
        } finally {
            // الغاء التسليم يعيد البضاعة، ثم يمكن الغاء الاستلام وتعود الطلبية «مؤكدة».
            app(DeliveryNoteService::class)->cancel($note);
            app(GoodsReceiptService::class)->cancel($receipt->refresh());
            $this->assertSame(0.0, app(StockLedgerService::class)->onHand($product->id));
            $this->assertSame(PurchaseOrderStatus::CONFIRMED, $order->refresh()->status);
            $this->assertSame(0.0, $this->balance($this->account('2125')));
        }
    }

    public function test_direct_shipment_never_enters_the_warehouse(): void
    {
        $product = $this->product('بروفيل مباشر', 'BLT3');
        $sales = $this->salesOrder([[$product, 10]]);
        $order = $this->quantityPurchaseOrder($product, 10, 7);
        $order->update(['delivery_type' => 'direct', 'direct_customer_id' => $this->customer->id, 'direct_sales_order_id' => $sales->id]);

        $this->assertSame(0.0, app(StockLevelService::class)->levels([$product->id])[$product->id]['incoming']);
        $this->assertSame(0.0, app(StockLevelService::class)->levels([$product->id])[$product->id]['reserved']);

        try {
            $this->receive($order, ['item' => 10]);
            $this->fail('Direct shipments cannot be received.');
        } catch (DomainException) {
        }

        $invoice = app(PurchaseInvoiceService::class)->createFromOrder($order);
        app(PurchaseInvoiceService::class)->post($invoice);

        $this->assertSame(70.0, $this->balance($this->account('5100')));
        $this->assertSame(0.0, $this->balance($this->account('2125')));
    }

    public function test_transfer_between_warehouses_moves_value_between_their_accounts(): void
    {
        $second = $this->warehouse('مخزن الطابق العلوي', 'WH2');
        $product = $this->product('حلق', 'BLT352');
        $this->openingBalance($product, [[10, null, 12]]);

        app(StockTransferService::class)->post(app(StockTransferService::class)->create([
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $second->id,
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ]));

        $this->assertSame(6.0, app(StockLedgerService::class)->onHand($product->id, $this->warehouse->id));
        $this->assertSame(4.0, app(StockLedgerService::class)->onHand($product->id, $second->id));
        $this->assertSame(72.0, $this->balance($this->warehouse->refresh()->inventory_account_id));
        $this->assertSame(48.0, $this->balance($second->refresh()->inventory_account_id));
        $this->assertSame(12.0, app(StockLedgerService::class)->averageCost($product->id));
    }

    public function test_moving_stock_to_an_overflow_location_needs_no_journal(): void
    {
        $product = $this->product('قاطع', 'BLT481');
        [$primary, $overflow] = $this->locations(['AG7', 'AG8']);
        app(WarehouseLayoutService::class)->assign($product->id, $primary);
        $this->openingBalance($product, [[10, $primary->id, 5]]);

        $transfer = app(StockTransferService::class)->post(app(StockTransferService::class)->create([
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'from_location_id' => $primary->id, 'to_location_id' => $overflow->id]],
        ]));

        $this->assertNull($transfer->journal_entry_id);
        $this->assertSame(6.0, app(StockLedgerService::class)->onHand($product->id, $this->warehouse->id, $primary->id));
        $this->assertSame(4.0, app(StockLedgerService::class)->onHand($product->id, $this->warehouse->id, $overflow->id));
        $this->assertTrue(ProductLocation::query()->where('product_id', $product->id)->where('location_id', $overflow->id)->where('is_primary', false)->exists());
    }

    public function test_stock_count_books_shortage_and_surplus(): void
    {
        $short = $this->product('ناقص', 'C1');
        $extra = $this->product('زائد', 'C2');
        $this->openingBalance($short, [[10, null, 3]]);
        $this->openingBalance($extra, [[2, null, 5]]);

        $count = app(StockAdjustmentService::class)->post(app(StockAdjustmentService::class)->create([
            'type' => 'count',
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_id' => $short->id, 'counted_quantity' => 7],
                ['product_id' => $extra->id, 'counted_quantity' => 5],
            ],
        ]));

        $this->assertSame(7.0, app(StockLedgerService::class)->onHand($short->id));
        $this->assertSame(5.0, app(StockLedgerService::class)->onHand($extra->id));
        // عجز 3 × 3 = 9، زيادة 3 × 5 = 15 → صافي دائن 6 على حساب عجز المخزون.
        $this->assertSame(-6.0, $this->balance($this->account('5140')));
        $this->assertSame(6.0, (float) $count->total_cost);
    }

    public function test_layout_parses_aisle_ranges_and_imports_old_shelf_numbers(): void
    {
        $layout = app(WarehouseLayoutService::class);

        $this->assertSame(['A', 'B', 'C'], $layout->parseAisles('A-C'));
        $this->assertSame(['AA', 'AB', 'AC', 'H'], $layout->parseAisles('AA-AC, h'));
        $this->assertSame(['aisle' => 'E', 'position' => 10, 'code' => 'E10'], $layout->parseShelf(' e10 '));
        $this->assertNull($layout->parseShelf('رف'));

        $this->assertSame(24, $layout->generate($this->warehouse, ['F', 'G'], 1, 12, 'الطابق السفلي'));
        $this->assertSame(0, $layout->generate($this->warehouse, ['F'], 1, 12));

        $product = $this->product('عليون 3 مجاري K3317', 'BLT476');
        $product->update(['shelf' => 'E10']);
        $result = $layout->importFromProductShelves($this->warehouse);

        $this->assertSame(1, $result['assigned']);
        $location = WarehouseLocation::query()->where('warehouse_id', $this->warehouse->id)->where('code', 'E10')->firstOrFail();
        $this->assertSame($location->id, $layout->primaryLocationId($product->id, $this->warehouse->id));
    }

    // ---------------------------------------------------------------- helpers

    public function test_a_main_item_holds_an_opening_balance_in_kg(): void
    {
        $main = $this->product('بدون دهان 1025', 'M-1025', 'weight');
        $this->product('ثابت 3 مجاري', 'S-1025', 'weight', $main->id, 1.2, 5);

        $this->openingBalance($main, [[1, null, 10]]);

        $this->assertSame(1.0, app(StockLedgerService::class)->onHand($main->id));
        $this->assertSame(1.0, (float) $main->refresh()->stock_weight);
        $this->assertSame(10.0, app(StockLedgerService::class)->averageCost($main->id));
    }

    public function test_opening_a_weight_piece_gives_it_a_count_and_no_value(): void
    {
        // القطعة 5 م × 1.2 كغم/م = 6 كغم للمعلومة، وقيمتها صفر ولو كان للكغم تكلفة.
        $main = $this->product('بدون دهان 1025', 'M-1026', 'weight');
        $sub = $this->product('ثابت 3 مجاري', 'S-1026', 'weight', $main->id, 1.2, 5);

        $this->openingBalance($sub, [[2, null, null]]);

        $this->assertSame(2.0, app(StockLedgerService::class)->onHand($sub->id));
        $this->assertSame(12.0, (float) $sub->refresh()->stock_weight);
        $this->assertSame(0.0, app(StockLedgerService::class)->averageCost($sub->id));
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

    /**
     * صنف رئيسي موزون (3 للكغ) بقطعتين: الاول 10 قطع × 6 م × 1.5 = 90 كغ، الثاني 20 × 6 × 0.75 = 90 كغ.
     *
     * @return array{0: PurchaseOrder, 1: Product, 2: Product}
     */
    private function weightPurchaseOrder(): array
    {
        $main = $this->product('المنيوم سبير لون ميتالك 9007', 'MAIN1', 'weight');
        $subA = $this->product('عليون 3 مجاري K3317', 'BLT476', 'weight', $main->id, 1.5, 6);
        $subB = $this->product('حلق 4500 K3403', 'BLT352', 'weight', $main->id, 0.75, 6);

        $order = $this->order(3000);
        $item = $order->items()->create([
            'product_id' => $main->id,
            'description' => $main->name,
            'quantity' => 1,
            'unit_price' => 3,
            'line_total' => 3000,
            'total_weight' => 1000,
        ]);
        $item->pieces()->create(['product_id' => $subA->id, 'description' => $subA->name, 'piece_count' => 10, 'piece_length' => 6, 'weight_per_meter' => 1.5, 'total_weight' => 90, 'sort_order' => 0]);
        $item->pieces()->create(['product_id' => $subB->id, 'description' => $subB->name, 'piece_count' => 20, 'piece_length' => 6, 'weight_per_meter' => 0.75, 'total_weight' => 90, 'sort_order' => 1]);

        return [$order->refresh(), $subA, $subB];
    }

    private function quantityPurchaseOrder(Product $product, float $quantity, float $price): PurchaseOrder
    {
        $order = $this->order($quantity * $price);
        $order->items()->create([
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => $quantity,
            'unit_price' => $price,
            'line_total' => $quantity * $price,
        ]);

        return $order->refresh();
    }

    private function order(float $total): PurchaseOrder
    {
        return PurchaseOrder::create([
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
    }

    /**
     * @param  array<string, float>  $quantities  «piece:A» / «piece:B» لقطع الصنف الموزون، «item» لبند الكمية
     */
    private function receive(PurchaseOrder $order, array $quantities)
    {
        $order->load('items.pieces');
        $item = $order->items->first();
        $pieces = $item->pieces->values();
        $items = [];

        foreach ($quantities as $key => $quantity) {
            $items[] = match ($key) {
                'piece:A' => ['purchase_order_item_id' => $item->id, 'purchase_order_item_piece_id' => $pieces[0]->id, 'quantity' => $quantity],
                'piece:B' => ['purchase_order_item_id' => $item->id, 'purchase_order_item_piece_id' => $pieces[1]->id, 'quantity' => $quantity],
                default => ['purchase_order_item_id' => $item->id, 'quantity' => $quantity],
            };
        }

        return app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'receipt_date' => now()->toDateString(),
            'items' => $items,
        ]);
    }

    /**
     * @param  array<int, array{0: Product, 1: float}>  $lines
     */
    private function salesOrder(array $lines): SalesOrder
    {
        $order = SalesOrder::create([
            'reference_no' => 'SO-'.Str::random(6),
            'client_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'exchange_rate' => 1,
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

    /**
     * @param  array<int, array{0: float, 1: ?int, 2: float}>  $lines  الكمية، الموقع، الكلفة
     */
    private function openingBalance(Product $product, array $lines): void
    {
        app(StockAdjustmentService::class)->post(app(StockAdjustmentService::class)->create([
            'type' => 'opening',
            'warehouse_id' => $this->warehouse->id,
            'items' => array_map(fn (array $line) => [
                'product_id' => $product->id,
                'quantity' => $line[0],
                'location_id' => $line[1],
                'unit_cost' => $line[2],
            ], $lines),
        ]));
    }

    /**
     * @return array<int, WarehouseLocation>
     */
    private function locations(array $codes): array
    {
        return array_map(fn (string $code) => WarehouseLocation::create([
            'warehouse_id' => $this->warehouse->id,
            'aisle' => preg_replace('/\d+/', '', $code),
            'position' => (int) preg_replace('/\D+/', '', $code),
        ]), $codes);
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
}
