<?php

namespace Modules\Accounting\tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Services\GoodsReceiptService;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Accounting\Services\PurchaseOrderService;
use Modules\Accounting\Services\PurchaseQuotationService;
use Modules\Accounting\tests\Unit\ConsolidatedTestFactory;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * طلبية الشراء بسعر اختياري: السعر يظهر ويُحفظ، صفر او فارغ جائز، وسعر عرض الشراء المعتمد
 * يتقدّم عليه في الفاتورة وفي قيمة الاستلام.
 */
class PurchaseOrderPriceTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Warehouse $warehouse;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $this->withoutVite();

        $user = User::factory()->create();
        Permission::findOrCreate('Purchase Order Create', 'web');
        $user->givePermissionTo('Purchase Order Create');
        $this->actingAs($user);
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

    public function test_the_order_saves_its_price_and_totals(): void
    {
        $simple = $this->product('simple');
        [$main, $piece] = $this->weightItems();

        $order = $this->order([
            ['product_id' => $simple->id, 'quantity' => 10, 'unit_price' => 8],
            // 8 كغم فعلي × 11 = 88
            ['product_id' => $main->id, 'quantity' => 1, 'unit_price' => 11, 'actual_weight' => 8, 'pieces' => [$this->piece($piece)]],
            // وزن بلا وزن فعلي: السعر يبقى والمبلغ صفر.
            ['product_id' => $main->id, 'quantity' => 1, 'unit_price' => 11, 'pieces' => [$this->piece($piece)]],
        ]);

        $items = $order->items->values();
        $this->assertSame(80.0, (float) $items[0]->line_total);
        $this->assertSame(88.0, (float) $items[1]->line_total);
        $this->assertSame(11.0, (float) $items[2]->unit_price);
        $this->assertSame(0.0, (float) $items[2]->line_total);
        $this->assertSame(168.0, (float) $order->sub_total);
        $this->assertSame(168.0, (float) $order->total_amount);
    }

    public function test_a_zero_or_empty_price_stays_valid(): void
    {
        $order = $this->order([
            ['product_id' => $this->product('a')->id, 'quantity' => 2, 'unit_price' => 0],
            ['product_id' => $this->product('b')->id, 'quantity' => 3, 'unit_price' => ''],
            ['product_id' => $this->product('c')->id, 'quantity' => 4],
        ]);

        $this->assertSame(0.0, (float) $order->total_amount);
        $this->assertSame([0.0, 0.0, 0.0], $order->items->map(fn ($item) => (float) $item->unit_price)->all());
    }

    public function test_a_negative_price_is_refused(): void
    {
        $this->expectException(DomainException::class);

        $this->order([['product_id' => $this->product('a')->id, 'quantity' => 2, 'unit_price' => -1]]);
    }

    public function test_the_invoice_draft_uses_the_order_price_without_a_quotation(): void
    {
        $order = $this->confirmed([['product_id' => $this->product('a')->id, 'quantity' => 10, 'unit_price' => 8]]);

        $draft = app(PurchaseInvoiceService::class)->draftFromOrder($order);

        $this->assertSame(8.0, (float) $draft['items'][0]['unit_price']);
    }

    public function test_an_approved_quotation_price_wins_over_the_order_price(): void
    {
        $order = $this->confirmed([['product_id' => $this->product('a')->id, 'quantity' => 10, 'unit_price' => 8]]);

        $this->assertSame(80.0, app(GoodsReceiptService::class)->lineValues($order)[$order->items->first()->id]);

        $quotation = $this->quote($order, 9);
        app(PurchaseQuotationService::class)->approve($quotation);

        $order = PurchaseOrder::query()->findOrFail($order->id);
        $draft = app(PurchaseInvoiceService::class)->draftFromOrder($order);

        $this->assertSame(9.0, (float) $draft['items'][0]['unit_price']);
        $this->assertSame(90.0, app(GoodsReceiptService::class)->lineValues($order)[$order->items->first()->id]);
    }

    public function test_a_draft_quotation_still_blocks_the_invoice(): void
    {
        $order = $this->confirmed([['product_id' => $this->product('a')->id, 'quantity' => 10, 'unit_price' => 8]]);
        $this->quote($order, 9);

        $this->expectException(DomainException::class);

        app(PurchaseInvoiceService::class)->draftFromOrder(PurchaseOrder::query()->findOrFail($order->id));
    }

    public function test_line_values_follow_the_order_price(): void
    {
        [$main, $piece] = $this->weightItems();
        $unpriced = $this->product('a');
        ConsolidatedTestFactory::averageCost($unpriced, 8);
        $order = $this->confirmed([
            // وزن بلا وزن فعلي: الوزن المحسوب 7.602 × 11 = 83.622
            ['product_id' => $main->id, 'quantity' => 1, 'unit_price' => 11, 'pieces' => [$this->piece($piece)]],
            // وزن بوزن فعلي: 8 × 11 = 88
            ['product_id' => $main->id, 'quantity' => 1, 'unit_price' => 11, 'actual_weight' => 8, 'pieces' => [$this->piece($piece)]],
            // بلا سعر: كلفة مؤقتة من متوسط تكلفة الصنف (لا من سعر البيع) 8 × 5 = 40
            ['product_id' => $unpriced->id, 'quantity' => 5, 'unit_price' => 0],
        ]);

        $values = app(GoodsReceiptService::class)->lineValues($order);
        $items = $order->items->values();

        $this->assertSame(83.622, $values[$items[0]->id]);
        $this->assertSame(88.0, $values[$items[1]->id]);
        $this->assertSame(40.0, $values[$items[2]->id]);
    }

    public function test_the_page_prices_lines_from_the_supplier_and_offers_to_reprice_on_party_change(): void
    {
        $product = $this->product('a');
        ConsolidatedTestFactory::averageCost($product, 8);
        $prices = fn (?int $party) => $this->getJson(route('accounting.items-grid.prices', ['document' => 'purchase-order', 'ids' => [$product->id], 'party_id' => $party]))->assertOk()->json();

        // بلا مورد لا سعر تلقائي.
        $this->assertSame(0.0, (float) ($prices(null)[$product->id] ?? 0));

        // مع المورد: متوسط تكلفة الشراء، فتعرض الصفحة تغيير الاسعار عند تغيير المورد.
        $this->assertSame(8.0, (float) $prices($this->supplier->id)[$product->id]);
    }

    public function test_the_page_saves_the_price_entered_on_the_grid(): void
    {
        $product = $this->product('a');

        $this->post(route('accounting.purchase-orders.store'), [
            'client_id' => $this->supplier->id,
            'branch_id' => null,
            'order_date' => now()->toDateString(),
            'delivery_type' => 'warehouse',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 7, 'pricing_type' => 'simple']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $order = PurchaseOrder::query()->with('items')->latest('id')->firstOrFail();

        $this->assertSame(7.0, (float) $order->items->first()->unit_price);
        $this->assertSame(21.0, (float) $order->total_amount);
    }

    private function order(array $items): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->create([
            'client_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => $items,
        ]);
    }

    private function confirmed(array $items): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->confirm($this->order($items));
    }

    private function quote(PurchaseOrder $order, float $price): PurchaseQuotation
    {
        return app(PurchaseQuotationService::class)->createFromOrder($order, [
            'quotation_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'items' => $order->items()->get()->map(fn ($item) => ['purchase_order_item_id' => $item->id, 'unit_price' => $price])->all(),
        ]);
    }

    private function product(string $name, array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'slug' => 'po-'.Str::lower(Str::random(8)),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => 'PO-'.Str::random(6),
            'base_price' => 10,
            'cost_price' => 8,
            'sale_price' => 20,
            'pricing_type' => 'simple',
        ], $attributes));
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    /**
     * @return array{0: Product, 1: Product}
     */
    private function weightItems(): array
    {
        $main = $this->product('main', ['pricing_type' => 'weight', 'base_price' => 0, 'cost_price' => null]);
        $piece = $this->product('piece', ['pricing_type' => 'weight', 'main_product_id' => $main->id, 'base_price' => 0, 'cost_price' => null, 'length' => 6, 'weight_per_meter' => 1.267]);

        return [$main, $piece];
    }

    /**
     * قطعة واحدة بطول 6 ووزن متر 1.267: الوزن المحسوب 7.602.
     */
    private function piece(Product $piece): array
    {
        return ['product_id' => $piece->id, 'piece_count' => 1, 'piece_length' => 6, 'weight_per_meter' => 1.267];
    }
}
