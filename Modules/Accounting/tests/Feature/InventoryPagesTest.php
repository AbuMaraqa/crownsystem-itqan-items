<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\StockAdjustment;
use Modules\Accounting\Entities\StockCostLayer;
use Modules\Accounting\Entities\StockTransfer;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Entities\WarehouseLocation;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Services\StockAdjustmentService;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Accounting\Support\InventoryPermissions;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * شاشات المخازن في لوحة Vue كما يستعملها الموظف: تخطيط المخزن، سند الاستلام من طلبية الشراء،
 * سند التسليم لطلبية البيع، التحويل، الجرد، بطاقة الصنف، الارصدة، والمطبوعات؛ مع اثر كل ترحيل
 * (الرصيد، القيد، طبقات الكلفة) والصلاحيات.
 */
class InventoryPagesTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;

    private Currency $currency;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // وسيطا اللغة يحوّلان الى /ar/... غير المسجَّل في بيئة الاختبار.
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $this->user = $this->userWith(array_merge(
            array_merge(...array_values(InventoryPermissions::GROUPS)),
            ['Purchase Order View List', 'Purchase Order Update', 'Purchase Order View', 'Warehouse View List', 'Warehouse Create', 'Journal Entry View'],
        ));
        $this->actingAs($this->user);

        $this->currency = Currency::create([
            'code' => 'ILS', 'symbol' => '₪', 'symbol_position' => 'before', 'decimal_places' => 2,
            'exchange_rate' => 1, 'is_active' => true, 'is_default' => true,
        ]);
        $year = FiscalYear::create([
            'name' => 'FY', 'starts_at' => now()->startOfYear()->toDateString(), 'ends_at' => now()->endOfYear()->toDateString(),
            'status' => AccountingPeriodStatus::OPEN->value(), 'is_default' => true,
        ]);
        AccountingPeriod::create([
            'fiscal_year_id' => $year->id, 'name' => 'P', 'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(), 'status' => AccountingPeriodStatus::OPEN->value(),
        ]);
        $this->seed(DefaultChartOfAccountsSeeder::class);
        $this->seed(AccountMappingSeeder::class);

        $this->warehouse = Warehouse::create(['code' => 'WH1', 'is_active' => true]);
        $this->warehouse->translateOrNew('ar')->name = 'المخزن الرئيسي';
        $this->warehouse->save();
    }

    public function test_full_warehouse_cycle_through_the_screens(): void
    {
        $this->warehouseCycle(false);
    }

    /**
     * الدورة كاملة عبر الشاشات: تخطيط، استلام، تسليم، تحويل، جرد، بطاقة الصنف والارصدة،
     * والمطبوعات عند $print.
     */
    private function warehouseCycle(bool $print): void
    {
        $product = $this->product('عليون 3 مجاري K3317', 'BLT476');
        $ledger = app(StockLedgerService::class);

        // 1) تخطيط المخزن: توليد الممرات E و F بالارقام 1..3، ووضع الصنف في E2.
        $this->post(route('accounting.inventory.layout.generate', $this->warehouse->id), ['aisles' => 'E-F', 'from' => 1, 'to' => 3])
            ->assertSessionHas('success');
        $this->assertSame(6, WarehouseLocation::query()->where('warehouse_id', $this->warehouse->id)->count());
        $e2 = WarehouseLocation::query()->where('code', 'E2')->firstOrFail();

        $this->post(route('accounting.inventory.layout.place', $e2->id), ['product_id' => $product->id, 'direction' => 'left', 'is_primary' => true])
            ->assertSessionHas('success');
        $this->get(route('accounting.inventory.layout', ['warehouse' => $this->warehouse->id, 'location' => $e2->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Inventory/WarehouseLayout/Index')
                ->where('selected.code', 'E2')
                ->where('selected.rows.0.product_id', $product->id)
                ->where('map.stats.locations', 6));

        // 2) سند استلام من طلبية الشراء: السطور تُحمَّل بالمتبقي وموقع الصنف الاساسي.
        $order = $this->purchaseOrder($product, 10, 25);
        $this->get(route('accounting.inventory.goods-receipts.add', ['purchase_order' => $order->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Inventory/GoodsReceipt/Add')
                ->where('receipt.items.0.quantity', 10)
                ->where('receipt.items.0.location_id', $e2->id)
                ->where('order.reference_no', $order->reference_no)
                ->where('value', '250'));

        $this->post(route('accounting.inventory.goods-receipts.store'), [
            'purchase_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'receipt_date' => now()->toDateString(), 'post' => true,
            'items' => [['purchase_order_item_id' => $order->items->first()->id, 'quantity' => 8, 'location_id' => $e2->id]],
        ])->assertRedirect()->assertSessionHas('success');

        $grn = GoodsReceipt::query()->firstOrFail();
        $this->assertSame(InventoryDocumentStatus::POSTED, $grn->status);
        $this->assertSame(8.0, $ledger->onHand($product->id, $this->warehouse->id, $e2->id));
        $this->assertBalancedEntry($grn->journal_entry_id, 200.0);
        $this->assertSame(8.0, (float) StockCostLayer::query()->where('product_id', $product->id)->sum('remaining_quantity'));

        $this->get(route('accounting.inventory.goods-receipts.edit', $grn->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Inventory/GoodsReceipt/Edit')
                ->where('record.reference_no', $grn->reference_no)
                ->where('record.is_posted', true)
                ->where('record.journal_entry.id', $grn->journal_entry_id)
                ->where('receipt.items.0.quantity', 8));
        $this->get(route('accounting.purchase-orders.edit', $order->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/PurchaseOrder/Edit')
                ->where('record.receiving.receipts.0.reference_no', $grn->reference_no));

        // 3) سند تسليم لطلبية البيع: «تعبئة من الرصيد المتوفر» ثم الترحيل.
        $sales = $this->salesOrder($product, 5);
        $this->get(route('accounting.inventory.delivery-notes.add', ['sales_order' => $sales->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Inventory/DeliveryNote/Add')
                ->where('note.items.0.outstanding', 5)
                ->where('context.levels.'.$product->id.'.on_hand', 8));

        $line = ['key' => 'item:'.$sales->items->first()->id, 'product_id' => $product->id, 'outstanding' => 5];
        $this->postJson(route('accounting.inventory.delivery-notes.available'), ['warehouse_id' => $this->warehouse->id, 'items' => [$line]])
            ->assertOk()
            ->assertExactJson([$line['key'] => 5]);

        $this->post(route('accounting.inventory.delivery-notes.store'), [
            'sales_order_id' => $sales->id, 'warehouse_id' => $this->warehouse->id, 'delivery_date' => now()->toDateString(), 'post' => true,
            'items' => [['sales_order_item_id' => $sales->items->first()->id, 'quantity' => 5]],
        ])->assertSessionHas('success');

        $note = DeliveryNote::query()->firstOrFail();
        $this->assertSame(InventoryDocumentStatus::POSTED, $note->status);
        $this->assertSame(3.0, $ledger->onHand($product->id));
        $this->assertBalancedEntry($note->journal_entry_id, 125.0);
        $this->assertSame(3.0, (float) StockCostLayer::query()->where('product_id', $product->id)->sum('remaining_quantity'));
        $this->get(route('accounting.inventory.delivery-notes.edit', $note->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('note.items.0.picked.0.code', 'E2'));

        // 4) نقل داخل المخزن الى موقع اضافي.
        $f1 = WarehouseLocation::query()->where('code', 'F1')->firstOrFail();
        $this->get(route('accounting.inventory.transfers.add', ['warehouse' => $this->warehouse->id, 'product' => $product->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Inventory/StockTransfer/Add')
                ->where('transfer.items.0.product_id', $product->id)
                ->where('transfer.items.0.quantity', 1));

        $this->post(route('accounting.inventory.transfers.store'), [
            'from_warehouse_id' => $this->warehouse->id, 'to_warehouse_id' => $this->warehouse->id, 'transfer_date' => now()->toDateString(), 'post' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'from_location_id' => $e2->id, 'to_location_id' => $f1->id]],
        ])->assertSessionHas('success');

        $this->assertSame(InventoryDocumentStatus::POSTED, StockTransfer::query()->firstOrFail()->status);
        $this->assertSame(2.0, $ledger->onHand($product->id, $this->warehouse->id, $f1->id));

        // 5) جرد: كشف المخزن ثم العدد الفعلي 2 بدل 3 (عجز واحد).
        $this->getJson(route('accounting.inventory.adjustments.count-sheet', ['warehouse_id' => $this->warehouse->id]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.product_id', $product->id)
            ->assertJsonPath('0.system_quantity', 3);

        $this->post(route('accounting.inventory.adjustments.store'), [
            'type' => 'count', 'warehouse_id' => $this->warehouse->id, 'adjustment_date' => now()->toDateString(), 'post' => true,
            'items' => [['product_id' => $product->id, 'counted_quantity' => 2]],
        ])->assertSessionHas('success');

        $count = StockAdjustment::query()->firstOrFail();
        $this->assertSame(InventoryDocumentStatus::POSTED, $count->status);
        $this->assertSame(-1.0, (float) $count->items->first()->quantity);
        $this->assertSame(2.0, $ledger->onHand($product->id));
        $this->assertBalancedEntry($count->journal_entry_id, 25.0);

        // 6) بطاقة الصنف والارصدة والقوائم.
        $this->get(route('accounting.inventory.item-card', ['product' => $product->id, 'warehouse' => $this->warehouse->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Inventory/ItemCard/Index')
                ->where('product.barcode', 'BLT476')
                ->where('card.levels.on_hand', '2')
                ->where('card.movements.rows', fn ($rows) => collect($rows)->pluck('reference_no')->intersect([$grn->reference_no, $note->reference_no])->count() === 2));

        $this->get(route('accounting.inventory.stock.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Inventory/StockBalances/Index')
                ->where('table.data.0.barcode', 'BLT476')
                ->where('table.data.0.on_hand', '2'));

        foreach (['goods-receipts' => 'GoodsReceipt', 'delivery-notes' => 'DeliveryNote', 'transfers' => 'StockTransfer', 'adjustments' => 'StockAdjustment'] as $prefix => $page) {
            $this->get(route("accounting.inventory.{$prefix}.index"))
                ->assertOk()
                ->assertInertia(fn (Assert $inertia) => $inertia->component("Accounting/Inventory/{$page}/Index")->where('table.meta.total', 1));
        }

        foreach (['accounting.inventory.stock.index', 'accounting.inventory.layout', 'accounting.inventory.item-card', 'accounting.inventory.goods-receipts.add', 'accounting.inventory.follow-up.parts'] as $name) {
            $this->get(route($name))->assertOk();
        }

        if (! $print) {
            return;
        }

        // 7) المطبوعات.
        foreach ([
            route('accounting.inventory.goods-receipts.pdf', $grn->id),
            route('accounting.inventory.delivery-notes.pdf', $note->id),
            route('accounting.inventory.transfers.pdf', StockTransfer::query()->first()->id),
            route('accounting.inventory.adjustments.pdf', $count->id),
            route('accounting.inventory.print.stock-report', ['warehouse' => $this->warehouse->id]),
            route('accounting.inventory.print.shelf-labels', ['warehouse' => $this->warehouse->id]),
        ] as $url) {
            // mPDF يكتب الملف مباشرة الى المخرجات (stream)، فيُلتقط ويُتحقق انه PDF.
            ob_start();
            $response = $this->get($url);
            $output = (string) ob_get_clean();
            $this->assertSame(200, $response->getStatusCode(), $url.' → '.$response->headers->get('location'));
            $this->assertStringStartsWith('%PDF', ltrim($output.$response->getContent()), $url);
        }
    }

    public function test_main_item_shows_its_own_kg_and_value_and_the_count_of_its_sub_items(): void
    {
        $main = $this->product('المنيوم سبير ابيض', 'MAIN439');
        $main->update(['pricing_type' => 'weight']);
        $subA = $this->product('دفة تريس K0581', 'BLT837B');
        $subB = $this->product('جنب 3 مجاري K4923', 'BLT1571');

        foreach ([$subA, $subB] as $sub) {
            $sub->update(['pricing_type' => 'weight', 'main_product_id' => $main->id, 'length' => 6, 'weight_per_meter' => 0.5]);
            $this->opening($sub, 10, 5);
        }

        // المقطع بلا قيمة ولو أُدخلت له كلفة؛ الكغم والقيمة على الصنف الرئيسي: 30 كغم × 4 = 120.
        $this->opening($main, 30, 4);

        // يظهر الصنف الرئيسي في الارصدة، ويُعرض وحده بفلتر «الاصناف الرئيسية».
        $this->get(route('accounting.inventory.stock.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('table.data', fn ($rows) => collect($rows)->pluck('barcode')->intersect(['MAIN439', 'BLT837B'])->count() === 2));
        $this->get(route('accounting.inventory.stock.index', ['filter' => ['main_items' => 1]]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.data', fn ($rows) => collect($rows)->pluck('barcode')->all() === ['MAIN439'])
                ->where('table.data.0.detail', __('Total of :count sub-items', ['count' => 2]))
                ->where('table.data.0.on_hand', '20')
                ->where('table.data.0.weight', '30')
                ->where('table.data.0.stock_value', '120'));

        // نوع الالمنيوم: الصنف الرئيسي ومقاطعه فقط.
        $this->get(route('accounting.inventory.stock.index', ['filter' => ['main_product' => $main->id]]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mainProduct.value', $main->id)
                ->where('table.meta.total', 3));

        $this->get(route('accounting.inventory.item-card', ['product' => $main->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('card.main.on_hand', '20')
                ->where('card.main.weight', '30')
                ->where('card.main.sub_items', 2)
                ->where('card.main.value', '120')
                ->where('card.sub_items', fn ($rows) => collect($rows)->pluck('code')->sort()->values()->all() === ['BLT1571', 'BLT837B']));

    }

    public function test_stock_balances_keep_the_old_warehouse_link(): void
    {
        $this->get(route('accounting.inventory.stock.index', ['tableFilters' => ['warehouse' => ['value' => $this->warehouse->id]]]))
            ->assertRedirect(route('accounting.inventory.stock.index', ['filter' => ['warehouse' => $this->warehouse->id]]));

        $this->get(route('accounting.inventory.stock.index', ['filter' => ['warehouse' => $this->warehouse->id]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('warehouseId', $this->warehouse->id));
    }

    public function test_draft_receipt_is_saved_edited_cancelled_and_deleted(): void
    {
        $product = $this->product('مفصلة', 'HNG1');
        $order = $this->purchaseOrder($product, 10, 25);
        $itemId = $order->items->first()->id;
        $data = ['purchase_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'receipt_date' => now()->toDateString(), 'container_no' => 'CONT-1'];

        // مسودة: لا تحرك المخزون.
        $this->post(route('accounting.inventory.goods-receipts.store'), [...$data, 'items' => [['purchase_order_item_id' => $itemId, 'quantity' => 4]]])
            ->assertSessionHas('success');
        $receipt = GoodsReceipt::query()->firstOrFail();
        $this->assertTrue($receipt->isDraft());
        $this->assertSame(0.0, app(StockLedgerService::class)->onHand($product->id));

        // تعديل الكمية، ثم رفض كمية اكبر من المتبقي برسالة الخدمة.
        $this->put(route('accounting.inventory.goods-receipts.update', $receipt->id), [...$data, 'items' => [['purchase_order_item_id' => $itemId, 'quantity' => 6]]])
            ->assertSessionHas('success');
        $this->assertSame(6.0, (float) $receipt->fresh()->total_quantity);
        $this->put(route('accounting.inventory.goods-receipts.update', $receipt->id), [...$data, 'items' => [['purchase_order_item_id' => $itemId, 'quantity' => 11]]])
            ->assertSessionHas('error')
            ->assertSessionHasErrors('document');

        // الترحيل من صفحة المسودة يحفظ الشاشة اولاً.
        $this->post(route('accounting.inventory.goods-receipts.post', $receipt->id), [...$data, 'items' => [['purchase_order_item_id' => $itemId, 'quantity' => 5]]])
            ->assertSessionHas('success');
        $receipt->refresh();
        $this->assertTrue($receipt->isPosted());
        $this->assertSame(5.0, app(StockLedgerService::class)->onHand($product->id));

        // الالغاء يعكس الرصيد والقيد.
        $this->post(route('accounting.inventory.goods-receipts.cancel', $receipt->id))->assertSessionHas('success');
        $receipt->refresh();
        $this->assertSame(InventoryDocumentStatus::CANCELLED, $receipt->status);
        $this->assertSame(0.0, app(StockLedgerService::class)->onHand($product->id));

        // مسودة اخرى تُحذف، والمرحّل/الملغى لا يُحذف.
        $this->post(route('accounting.inventory.goods-receipts.store'), [...$data, 'items' => [['purchase_order_item_id' => $itemId, 'quantity' => 1]]]);
        $draft = GoodsReceipt::query()->latest('id')->firstOrFail();
        $this->delete(route('accounting.inventory.goods-receipts.destroy', $draft->id))->assertRedirect(route('accounting.inventory.goods-receipts.index'));
        $this->assertSoftDeleted($draft);
        $this->delete(route('accounting.inventory.goods-receipts.destroy', $receipt->id))->assertSessionHas('error');
    }

    public function test_delivery_beyond_stock_is_refused_unless_negative_stock_is_allowed(): void
    {
        $product = $this->product('قفل', 'LCK1');
        $this->opening($product, 2, 10);
        $sales = $this->salesOrder($product, 5);
        $request = [
            'sales_order_id' => $sales->id, 'warehouse_id' => $this->warehouse->id, 'delivery_date' => now()->toDateString(), 'post' => true,
            'items' => [['sales_order_item_id' => $sales->items->first()->id, 'quantity' => 5]],
        ];

        // بلا «Stock Allow Negative»: تُحفظ المسودة ويُرفض الترحيل بسطر لكل صنف ناقص.
        $this->actingAs($this->userWith(['Delivery Note View List', 'Delivery Note Create', 'Delivery Note Post']));
        $this->post(route('accounting.inventory.delivery-notes.store'), $request)
            ->assertRedirect(route('accounting.inventory.delivery-notes.edit', DeliveryNote::query()->latest('id')->value('id')))
            ->assertSessionHasErrors('document');
        $this->assertTrue(DeliveryNote::query()->firstOrFail()->isDraft());
        $this->assertSame(2.0, app(StockLedgerService::class)->onHand($product->id));

        // صاحب الصلاحية يسلّم فوق الرصيد.
        $this->actingAs($this->user);
        $note = DeliveryNote::query()->firstOrFail();
        $this->post(route('accounting.inventory.delivery-notes.post', $note->id), $request)->assertSessionHas('success');
        $this->assertSame(-3.0, app(StockLedgerService::class)->onHand($product->id));
    }

    public function test_each_action_needs_its_permission(): void
    {
        $product = $this->product('برغي', 'SCR1');
        $order = $this->purchaseOrder($product, 3, 2);
        $data = [
            'purchase_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'receipt_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $order->items->first()->id, 'quantity' => 3]],
        ];

        $viewer = $this->userWith(['Goods Receipt View List', 'Goods Receipt Create']);
        $this->actingAs($viewer);

        // الانشاء مسموح، والترحيل عند الانشاء يحتاج «Goods Receipt Post».
        $this->post(route('accounting.inventory.goods-receipts.store'), [...$data, 'post' => true])->assertForbidden();
        $this->post(route('accounting.inventory.goods-receipts.store'), $data)->assertSessionHas('success');
        $receipt = GoodsReceipt::query()->firstOrFail();

        $this->get(route('accounting.inventory.goods-receipts.edit', $receipt->id))->assertOk();
        $this->put(route('accounting.inventory.goods-receipts.update', $receipt->id), $data)->assertForbidden();
        $this->post(route('accounting.inventory.goods-receipts.post', $receipt->id), $data)->assertForbidden();
        $this->delete(route('accounting.inventory.goods-receipts.destroy', $receipt->id))->assertForbidden();
        $this->get(route('accounting.inventory.delivery-notes.index'))->assertForbidden();
        $this->get(route('accounting.inventory.stock.index'))->assertForbidden();
        $this->post(route('accounting.inventory.layout.generate', $this->warehouse->id), ['aisles' => 'A', 'from' => 1, 'to' => 2])->assertForbidden();
        $this->postJson(route('accounting.inventory.goods-receipts.value'), ['purchase_order_id' => $order->id])->assertForbidden();
        $this->assertTrue($receipt->fresh()->isDraft());

        // كلفة السطور وقيمة المستند لا تُرسل لمن لا يرى قيمة المخزن.
        $this->get(route('accounting.inventory.goods-receipts.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canSeeCost', false)->where('table.data.0.total_cost', null));
    }

    public function test_adjustment_pages_add_lines_and_post_an_opening_balance(): void
    {
        $product = $this->product('مقبض', 'HDL1');

        $this->get(route('accounting.inventory.adjustments.add', ['type' => 'opening', 'product' => $product->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Inventory/StockAdjustment/Add')
                ->where('adjustment.type', 'opening')
                ->where('adjustment.warehouse_id', $this->warehouse->id)
                ->where('adjustment.items.0.quantity', 1));

        $this->getJson(route('accounting.inventory.adjustments.line', ['product_id' => $product->id, 'type' => 'count', 'warehouse_id' => $this->warehouse->id]))
            ->assertOk()
            ->assertJsonPath('counted_quantity', 1)
            ->assertJsonPath('quantity', null);
        $this->getJson(route('accounting.inventory.adjustments.products', ['q' => 'HDL1']))
            ->assertOk()
            ->assertJsonPath('0.value', $product->id);

        $this->post(route('accounting.inventory.adjustments.store'), [
            'type' => 'opening', 'warehouse_id' => $this->warehouse->id, 'adjustment_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 7, 'unit_cost' => 3]],
        ])->assertSessionHas('success');
        $adjustment = StockAdjustment::query()->firstOrFail();

        $this->post(route('accounting.inventory.adjustments.post', $adjustment->id), [
            'type' => 'opening', 'warehouse_id' => $this->warehouse->id, 'adjustment_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 7, 'unit_cost' => 3]],
        ])->assertSessionHas('success');

        $this->assertSame(7.0, app(StockLedgerService::class)->onHand($product->id));
        $this->assertBalancedEntry($adjustment->fresh()->journal_entry_id, 21.0);
        $this->assertSame(21.0, (float) StockCostLayer::query()->where('product_id', $product->id)->sum('remaining_value'));

        $this->post(route('accounting.inventory.adjustments.cancel', $adjustment->id))->assertSessionHas('success');
        $this->assertSame(0.0, app(StockLedgerService::class)->onHand($product->id));
    }

    /**
     * يأتي آخراً: بعد اول PDF يصبح الاسم المستعار PDF صنفاً معرَّفاً، فيُقرأ اي مسار فعله
     * النصي 'pdf' في التطبيقات التالية في نفس العملية كاسم صنف (Router::prependGroupController).
     */
    public function test_zz_documents_and_reports_print_as_pdf(): void
    {
        $this->warehouseCycle(true);
    }

    private function assertBalancedEntry(?int $entryId, float $amount): void
    {
        $this->assertNotNull($entryId);
        $entry = JournalEntry::query()->with('lines')->findOrFail($entryId);
        $this->assertEqualsWithDelta((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'), 0.0001);
        $this->assertEqualsWithDelta($amount, (float) $entry->lines->sum('debit'), 0.0001);
    }

    private function opening(Product $product, float $quantity, float $cost): void
    {
        $service = app(StockAdjustmentService::class);
        $service->post($service->create([
            'type' => 'opening',
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'location_id' => null, 'unit_cost' => $cost]],
        ]));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    protected function product(string $name, string $barcode): Product
    {
        $product = new Product([
            'slug' => 'p-'.Str::random(8), 'sku' => 'S-'.Str::random(6), 'barcode' => $barcode,
            'base_price' => 10, 'cost_price' => 8, 'pricing_type' => 'simple',
            'min_limit_1' => 5, 'min_limit_2' => 2, 'max_limit' => 20,
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    private function purchaseOrder(Product $product, float $quantity, float $price): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'reference_no' => 'PO-'.Str::random(6), 'client_id' => User::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id, 'order_date' => now()->toDateString(),
            'currency_id' => $this->currency->id, 'exchange_rate' => 1,
            'sub_total' => $quantity * $price, 'total_amount' => $quantity * $price,
            'status' => PurchaseOrderStatus::CONFIRMED->value(),
        ]);
        $order->items()->create(['product_id' => $product->id, 'description' => $product->name, 'quantity' => $quantity, 'unit_price' => $price, 'line_total' => $quantity * $price]);

        return $order->refresh();
    }

    private function salesOrder(Product $product, float $quantity): SalesOrder
    {
        $order = SalesOrder::create([
            'reference_no' => 'SO-'.Str::random(6), 'client_id' => User::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id, 'order_date' => now()->toDateString(),
            'currency_id' => $this->currency->id, 'exchange_rate' => 1,
            'status' => SalesOrderStatus::CONFIRMED->value(),
        ]);
        $order->items()->create(['product_id' => $product->id, 'description' => $product->name, 'quantity' => $quantity, 'unit_price' => 40, 'line_total' => $quantity * 40]);

        return $order->refresh();
    }
}
