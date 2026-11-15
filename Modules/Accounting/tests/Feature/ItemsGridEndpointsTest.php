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
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;
use Modules\Items\Entities\ProductPrice;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * نقاط جدول الاصناف (items-grid/{document}/*): البحث، تفاصيل الصنف بسعر الطرف وصنفه الرئيسي،
 * الباركود بكمية العبوة، مبالغ السطور وتنبيهاتها، الرصيد، والصلاحيات.
 */
class ItemsGridEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        foreach (['Sales Quotation Create', 'Sales Quotation Update', 'Stock View List', 'Product Update', 'Product Create'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

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

        $this->customer = User::factory()->create(['name' => 'زبون الجدول']);
        $this->customer->assignRole(Role::findOrCreate('Customer', 'web'));

        $this->signIn(['Sales Quotation Create']);
    }

    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function product(string $sku): Product
    {
        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    private function url(string $name, array $query = [], string $document = 'sales-quotation'): string
    {
        return route('accounting.items-grid.'.$name, ['document' => $document, ...$query]);
    }

    public function test_the_endpoints_need_the_documents_permission(): void
    {
        $this->signIn([]);
        $response = $this->getJson($this->url('products', ['q' => 'DEMO']));
        $this->assertSame(403, $response->status(), (string) $response->getContent());

        $this->signIn(['Sales Quotation Update']);
        $this->getJson($this->url('products', ['q' => 'DEMO']))->assertOk();

        // مستند لا يملك المستخدم صلاحياته لا يُفتح له، ولو كان الجدول نفسه.
        $this->getJson($this->url('products', ['q' => 'DEMO'], 'purchase-order'))->assertForbidden();
    }

    public function test_an_unknown_document_is_not_a_grid(): void
    {
        $this->get('/admin/accounting/items-grid/not-a-document/products')->assertNotFound();
    }

    public function test_a_sub_item_payload_carries_its_main_item_and_the_party_price(): void
    {
        $main = $this->product('DEMO-1025');
        $sub = $this->product('DEMO-1025-01');

        // سعر الطرف من قائمة اسعاره، وسعر سطر الوزن للصنف الرئيسي.
        $list = new PriceList(['same_buy_sell' => false, 'is_active' => true, 'sort_order' => 1]);
        $list->save();
        $list->translateOrNew(app()->getLocale())->name = 'قائمة الجدول';
        $list->save();
        ProductPrice::create(['price_list_id' => $list->id, 'product_id' => $main->id, 'sell_price' => 17.5, 'buy_price' => 0]);
        $this->customer->update(['price_list_id' => $list->id]);

        $payload = $this->getJson($this->url('item', ['product_id' => $sub->id, 'party_id' => $this->customer->id]))->assertOk()->json();

        $this->assertSame($sub->id, $payload['id']);
        $this->assertSame($main->id, $payload['main_product']['id']);
        $this->assertSame('17.5000', $payload['main_product']['unit_price']);
        $this->assertSame('weight', $payload['main_product']['pricing_type']);

        // بلا طرف لا سعر تلقائي، كما في اتقان.
        $this->assertSame('0.0000', $this->getJson($this->url('item', ['product_id' => $main->id]))->json('unit_price'));

        $prices = $this->getJson($this->url('prices', ['ids' => [$main->id], 'party_id' => $this->customer->id]))->assertOk()->json();
        $this->assertEquals(17.5, $prices[$main->id]);
    }

    public function test_the_barcode_returns_every_match_with_its_pack_quantity(): void
    {
        $hinge = $this->product('DEMO-HINGE');
        ProductBarcode::query()->create(['product_id' => $hinge->id, 'barcode' => 'BOX-12', 'pack_quantity' => 12, 'is_prefix' => false, 'sort_order' => 1]);

        $result = $this->getJson($this->url('barcode', ['code' => 'BOX-12']))->assertOk()->json();
        $this->assertCount(1, $result['matches']);
        $this->assertSame($hinge->id, $result['matches'][0]['id']);
        $this->assertEquals(12, (float) $result['matches'][0]['pack_quantity']);

        $missing = $this->getJson($this->url('barcode', ['code' => 'NO-SUCH-CODE']))->assertOk()->json();
        $this->assertSame([], $missing['matches']);
        $this->assertNull($missing['add_url'], 'only who can create items gets the add link');

        $this->signIn(['Sales Quotation Create', 'Product Create']);
        $this->assertNotNull($this->getJson($this->url('barcode', ['code' => 'NO-SUCH-CODE']))->json('add_url'));
    }

    public function test_the_search_and_the_sub_item_search_inside_a_main_item(): void
    {
        $main = $this->product('DEMO-1025');
        $sub = $this->product('DEMO-1025-01');

        $outputs = collect($this->getJson($this->url('piece-outputs', ['main_product_id' => $main->id]))->assertOk()->json())->pluck('id');
        $this->assertTrue($outputs->contains($sub->id));

        $results = collect($this->getJson($this->url('products', ['q' => 'DEMO-HINGE']))->assertOk()->json())->pluck('id');
        $this->assertTrue($results->contains($this->product('DEMO-HINGE')->id));
    }

    public function test_totals_price_a_weight_line_by_its_actual_weight_only(): void
    {
        $main = $this->product('DEMO-1025');
        $sub = $this->product('DEMO-1025-01');
        $line = fn (?float $actual): array => [
            'key' => 'row-1',
            'product_id' => $main->id,
            'unit_price' => 20,
            'actual_weight' => $actual,
            'discount_mode' => 'rate',
            'discount_rate' => 10,
            'pieces' => [['key' => 'piece-1', 'product_id' => $sub->id, 'piece_count' => 2, 'piece_length' => 6, 'weight_per_meter' => 1.2]],
        ];

        $result = $this->postJson($this->url('totals'), ['items' => [$line(15)], 'document_discount' => 5, 'tax_rate' => 16])->assertOk()->json();

        // قطعتان × 6 م × 1.2 = 14.4 كغم محسوبة؛ المبلغ بالوزن الفعلي 15 × 20 = 300، وخصم 10%.
        $this->assertEquals(14.4, $result['lines'][0]['total_weight']);
        $this->assertEquals(14.4, $result['lines'][0]['pieces'][0]['total_weight']);
        $this->assertEquals(300, $result['lines'][0]['gross']);
        $this->assertEquals(270, $result['lines'][0]['line_total']);
        $this->assertEquals(5, $result['totals']['document_discount']);
        $this->assertEquals(round(265 * 1.16, 4), $result['totals']['total_amount']);
        $this->assertSame([], $result['lines'][0]['notices']);

        // قرار صاحب النظام: بلا وزن فعلي المبلغ صفر، مع تنبيه لا يمنع الحفظ.
        $empty = $this->postJson($this->url('totals'), ['items' => [$line(null)]])->assertOk()->json('lines.0');
        $this->assertEquals(0, $empty['line_total']);
        $this->assertSame('actual_weight_empty', $empty['notices'][0]['type']);
    }

    public function test_the_stock_hint_is_for_who_sees_stock_and_cost_for_who_edits_items(): void
    {
        $main = $this->product('DEMO-1025');

        // null: who cannot see stock gets no hint.
        $this->assertSame('null', $this->getJson($this->url('stock', ['product_id' => $main->id]))->assertOk()->getContent());

        $this->signIn(['Sales Quotation Create', 'Stock View List']);
        $stock = $this->getJson($this->url('stock', ['product_id' => $main->id]))->assertOk()->json();
        $this->assertSame(__('kg'), $stock['unit']);
        $this->assertNull($stock['cost']);

        $this->signIn(['Sales Quotation Create', 'Stock View List', 'Product Update']);
        $this->assertNotNull($this->getJson($this->url('stock', ['product_id' => $main->id]))->json('cost'));
    }

    public function test_the_party_field_lists_customers_of_a_sales_document(): void
    {
        $supplier = User::factory()->create(['name' => 'مورد الجدول']);
        $supplier->assignRole(Role::findOrCreate('Supplier', 'web'));

        $ids = collect($this->getJson($this->url('parties', ['q' => 'الجدول']))->assertOk()->json())->pluck('value');

        $this->assertTrue($ids->contains($this->customer->id));
        $this->assertFalse($ids->contains($supplier->id));
    }
}
