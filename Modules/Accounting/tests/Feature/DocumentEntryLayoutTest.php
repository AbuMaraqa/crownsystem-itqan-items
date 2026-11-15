<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\database\seeders\DemoPurchasingSeeder;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Services\SalesInvoiceService;
use Modules\Accounting\Services\SalesOrderService;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductPrice;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ادخال المستند كما في اتقان: الطرف فوق، والبنود مباشرة في شاشة الاضافة،
 * وتغيير الطرف يسأل قبل تغيير الاسعار.
 */
class DocumentEntryLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // حفظ المستند يرسل اشعاراً مباشراً، ولا خادم بث في الاختبارات.
        config(['broadcasting.default' => 'null']);

        $permissions = [
            'Sales Invoice Create', 'Sales Invoice Update', 'Purchase Invoice Create', 'Purchase Invoice Update',
            'Sales Order Create', 'Sales Order Update',
        ];
        $user = User::factory()->create();
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        Currency::create([
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
        $this->seed(DemoPurchasingSeeder::class);
    }

    private function product(string $sku): Product
    {
        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    private function weightLine(float $actual = 16.2): array
    {
        return [
            'product_id' => $this->product('DEMO-1025')->id,
            'pricing_type' => 'weight',
            'unit_price' => 10,
            'actual_weight' => $actual,
            'pieces' => [['product_id' => $this->product('DEMO-1025-02')->id, 'piece_count' => 3, 'piece_length' => 6, 'weight_per_meter' => 0.9]],
        ];
    }

    private function partyWithPrice(Product $product, float $sell): User
    {
        $list = new PriceList(['same_buy_sell' => false, 'is_active' => true, 'sort_order' => 1]);
        $list->save();
        $list->translateOrNew(app()->getLocale())->name = 'قائمة '.$sell;
        $list->save();
        ProductPrice::create(['price_list_id' => $list->id, 'product_id' => $product->id, 'sell_price' => $sell, 'buy_price' => 0]);

        return User::factory()->create(['price_list_id' => $list->id]);
    }

    /**
     * صفحات البيع في لوحة Vue تُطلب مباشرة دون تحويل اللغة.
     */
    private function salesPages(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
    }

    /**
     * @return array<string, mixed>
     */
    private function salesInvoiceForm(mixed $clientId, array $items, array $overrides = []): array
    {
        return array_replace([
            'client_id' => $clientId,
            'warehouse_id' => Warehouse::query()->value('id'),
            'invoice_date' => now()->toDateString(),
            'currency_id' => Currency::query()->value('id'),
            'items' => $items,
            'services' => [],
        ], $overrides);
    }

    public function test_a_sales_invoice_is_saved_with_its_lines_from_the_add_page(): void
    {
        $this->salesPages();

        $this->post(route('accounting.sales-invoices.store'), $this->salesInvoiceForm(User::factory()->create()->id, [$this->weightLine()]))
            ->assertSessionHas('success');

        // 3 × 6 × 0.9 = 16.2 كغم × 10.
        $line = SalesInvoice::query()->latest('id')->firstOrFail()->items()->with('pieces')->firstOrFail();
        $this->assertSame(16.2, (float) $line->quantity);
        $this->assertSame(162.0, (float) $line->line_total);
        $this->assertCount(1, $line->pieces);
    }

    public function test_a_purchase_invoice_is_saved_with_its_lines_from_the_add_page(): void
    {
        $this->salesPages();

        $this->post(route('accounting.purchase-invoices.store'), [
            'client_id' => User::query()->where('name', 'مورد تجريبي - شركة الألمنيوم')->value('id'),
            'warehouse_id' => Warehouse::query()->value('id'),
            'invoice_date' => now()->toDateString(),
            'currency_id' => Currency::query()->value('id'),
            'items' => [$this->weightLine()],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $invoice = PurchaseInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame(162.0, (float) $invoice->items()->firstOrFail()->line_total);
    }

    public function test_a_manual_main_item_without_pieces_is_refused_on_the_add_page(): void
    {
        $this->salesPages();
        $before = SalesInvoice::query()->count();
        $line = array_replace($this->weightLine(), ['pieces' => [], 'quantity' => 30]);

        $this->post(route('accounting.sales-invoices.store'), $this->salesInvoiceForm(User::factory()->create()->id, [$line]))
            ->assertSessionHas('error');

        $this->assertSame($before, SalesInvoice::query()->count());
    }

    /**
     * تغيير الطرف لا يمس البنود اليدوية: الجدول (Vue) يسأل عن اسعار الطرف الجديد من نقطة الاسعار.
     */
    public function test_changing_the_party_keeps_manual_lines_and_announces_the_change(): void
    {
        $this->salesPages();
        $main = $this->product('DEMO-1025');
        $party = $this->partyWithPrice($main, 11);

        $this->getJson(route('accounting.items-grid.prices', ['document' => 'sales-invoice', 'ids' => [$main->id], 'party_id' => $party->id]))
            ->assertOk()
            ->assertJsonPath((string) $main->id, '11.0000');

        $this->post(route('accounting.sales-invoices.store'), $this->salesInvoiceForm($party->id, [$this->weightLine()]))->assertSessionHas('success');
        $this->assertSame($main->id, (int) SalesInvoice::query()->latest('id')->firstOrFail()->items()->value('product_id'));
    }

    public function test_line_prices_follow_the_new_party(): void
    {
        $this->salesPages();
        $main = $this->product('DEMO-1025');
        $cash = $this->partyWithPrice($main, 11);
        $cheques = $this->partyWithPrice($main, 12.5);
        $prices = fn (User $party) => $this->getJson(route('accounting.items-grid.prices', ['document' => 'sales-invoice', 'ids' => [$main->id], 'party_id' => $party->id]))->json((string) $main->id);

        $this->assertEquals(11, $prices($cash));
        $this->assertEquals(12.5, $prices($cheques));
    }

    /**
     * فاتورة الطلبية: اصنافها مقفلة في جدول Vue فلا يُسأل عن تغيير الاسعار؛ الصفحة ترسل انها من طلبية.
     */
    public function test_an_invoice_from_an_order_offers_no_repricing(): void
    {
        $this->salesPages();
        auth()->user()->givePermissionTo(Permission::findOrCreate('Sales Invoice View List', 'web'));
        $main = $this->product('DEMO-1025');
        $party = $this->partyWithPrice($main, 11);
        $order = app(SalesOrderService::class)->create([
            'client_id' => $party->id,
            'order_date' => now()->toDateString(),
            'warehouse_id' => Warehouse::query()->value('id'),
            'currency_id' => Currency::query()->value('id'),
            'exchange_rate' => 1,
            'items' => [$this->weightLine()],
        ]);
        $invoice = app(SalesInvoiceService::class)->createFromOrder($order);

        $this->get(route('accounting.sales-invoices.edit', $invoice->id))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('record.from_order', true)->where('record.editable', true));
    }

    public function test_choosing_an_order_on_the_add_page_replaces_the_grid_and_moves_its_lines(): void
    {
        $this->salesPages();
        $party = User::factory()->create();
        $order = app(SalesOrderService::class)->create([
            'client_id' => $party->id,
            'order_date' => now()->toDateString(),
            'warehouse_id' => Warehouse::query()->value('id'),
            'currency_id' => Currency::query()->value('id'),
            'exchange_rate' => 1,
            'items' => [$this->weightLine()],
        ]);
        $this->assertInstanceOf(SalesOrder::class, $order);

        // البنود لا تُرسل: الخدمة تأخذها من الطلبية.
        $this->post(route('accounting.sales-invoices.store'), $this->salesInvoiceForm($party->id, [], ['sales_order_id' => $order->id]))
            ->assertSessionHas('success');

        $invoice = SalesInvoice::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertCount(1, $invoice->items);
    }

    public function test_the_line_stock_shows_weight_for_a_main_item_and_count_for_a_piece(): void
    {
        $this->salesPages();
        Permission::firstOrCreate(['name' => 'Stock View List', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'Product Update', 'guard_name' => 'web']);
        auth()->user()->givePermissionTo(['Stock View List', 'Product Update']);
        auth()->user()->refresh();

        $main = $this->product('DEMO-1025');
        $piece = $this->product('DEMO-1025-02');
        $warehouse = Warehouse::query()->value('id');
        $stock = fn (Product $product) => $this->getJson(route('accounting.items-grid.stock', ['document' => 'sales-invoice', 'product_id' => $product->id, 'warehouse_id' => $warehouse]))->assertOk()->json();

        $pieceIds = Product::query()->where('main_product_id', $main->id)->pluck('id');
        $mainStock = $stock($main);
        $pieceStock = $stock($piece);

        $this->assertEquals(round((float) StockBalance::query()->whereIn('product_id', $pieceIds)->sum('weight'), 2), (float) $mainStock['all']);
        $this->assertSame(__('kg'), $mainStock['unit']);
        $this->assertNotNull($mainStock['cost']);
        $this->assertEquals(round((float) StockBalance::query()->where('product_id', $piece->id)->sum('quantity'), 2), (float) $pieceStock['all']);
        $this->assertNotNull($pieceStock['here']);
        $this->assertNull($pieceStock['cost']);
    }

    public function test_the_line_stock_needs_the_stock_permission_and_cost_needs_the_item_permission(): void
    {
        $this->salesPages();
        $main = $this->product('DEMO-1025');
        $url = route('accounting.items-grid.stock', ['document' => 'sales-invoice', 'product_id' => $main->id]);

        $this->assertSame('null', $this->getJson($url)->assertOk()->getContent());

        Permission::firstOrCreate(['name' => 'Stock View List', 'guard_name' => 'web']);
        auth()->user()->givePermissionTo('Stock View List');
        auth()->user()->refresh();

        $stock = $this->getJson($url)->assertOk()->json();
        $this->assertNotNull($stock);
        $this->assertNull($stock['cost']);
        $this->assertNull($stock['here']);
    }
}
