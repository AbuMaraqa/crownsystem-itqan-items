<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\database\seeders\AccountMappingSeeder;
use Modules\Accounting\database\seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\database\seeders\DemoPurchasingSeeder;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\SalesQuotation;
use Modules\Accounting\Enums\AccountingPeriodStatus;
use Modules\Accounting\Enums\SalesQuotationStatus;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductPrice;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * عروض اسعار البيع في لوحة Vue: القائمة وفلاترها، الاضافة والتعديل بسطور جدول الاصناف (سطر وزن
 * بقطعه وسعر من الطرف)، الاعتماد والتحويل الى طلبية والالغاء والحذف، وكل اجراء بصلاحيته.
 */
class SalesQuotationPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'Sales Quotation View List', 'Sales Quotation View', 'Sales Quotation Info', 'Sales Quotation Create',
        'Sales Quotation Update', 'Sales Quotation Delete', 'Sales Quotation Approve', 'Sales Quotation Cancel',
        'Sales Order Create',
    ];

    private User $customer;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->signIn();

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
        $this->seed(DemoPurchasingSeeder::class);

        $this->customer = User::factory()->create(['name' => 'زبون العروض']);
        $this->customer->assignRole(Role::findOrCreate('Customer', 'web'));
    }

    private function signIn(?array $permissions = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions ?? self::PERMISSIONS);
        $this->actingAs($user);

        return $user;
    }

    private function product(string $sku): Product
    {
        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    /**
     * سعر الكيلو من قائمة اسعار الطرف، كما يعطيه الجدول عند اختيار الصنف.
     */
    private function partyPrice(Product $product, float $sell): string
    {
        $list = new PriceList(['same_buy_sell' => false, 'is_active' => true, 'sort_order' => 1]);
        $list->save();
        $list->translateOrNew(app()->getLocale())->name = 'قائمة العروض';
        $list->save();
        ProductPrice::create(['price_list_id' => $list->id, 'product_id' => $product->id, 'sell_price' => $sell, 'buy_price' => 0]);
        $this->customer->update(['price_list_id' => $list->id]);

        return $this->getJson(route('accounting.items-grid.item', [
            'document' => 'sales-quotation',
            'product_id' => $product->id,
            'party_id' => $this->customer->id,
        ]))->assertOk()->json('unit_price');
    }

    /**
     * سطر صنف رئيسي بقطعه كما يرسله جدول Vue (مع حقول العرض التي يتجاهلها الحفظ).
     */
    private function weightLine(string $price, ?float $actual = 15): array
    {
        return [
            '_key' => 'row-a',
            'row_type' => 'item',
            'product_id' => $this->product('DEMO-1025')->id,
            'product_label' => 'بدون دهان 1025',
            'pricing_type' => 'weight',
            'quantity' => 1,
            'actual_weight' => $actual,
            'unit_price' => $price,
            'discount_mode' => 'rate',
            'discount_rate' => 0,
            'discount_amount' => 0,
            'collapsed' => false,
            'pieces' => [[
                '_key' => 'piece-a',
                'product_id' => $this->product('DEMO-1025-01')->id,
                'product_label' => 'ثابت 3 مجاري 1025',
                'piece_count' => 2,
                'piece_length' => 6,
                'weight_per_meter' => 1.2,
            ]],
        ];
    }

    private function data(array $items = [], array $overrides = []): array
    {
        return array_replace([
            'client_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(10)->toDateString(),
            'currency_id' => $this->currency->id,
            'warehouse_id' => null,
            'tax_id' => null,
            'quotation_discount' => 0,
            'notes' => 'ملاحظة العرض',
            'items' => $items,
        ], $overrides);
    }

    private function storedQuotation(): SalesQuotation
    {
        $price = $this->partyPrice($this->product('DEMO-1025'), 20);
        $this->post(route('accounting.sales-quotations.store'), $this->data([
            $this->weightLine($price),
            // صف فارغ وصف مجموعة لا يُحفظان.
            ['_key' => 'row-b', 'row_type' => 'item', 'product_id' => null, 'pieces' => []],
        ]))->assertSessionHas('success');

        return SalesQuotation::query()->latest('id')->firstOrFail();
    }

    public function test_the_list_shows_quotations_with_filters(): void
    {
        $quotation = $this->storedQuotation();

        $this->get(route('accounting.sales-quotations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/SalesQuotation/Index')
                ->has('table.data', 1)
                ->where('table.data.0.reference_no', $quotation->reference_no)
                ->where('table.data.0.client.name', 'زبون العروض')
                ->where('table.data.0.can_convert', true)
                ->has('statusOptions', 4));

        $this->get(route('accounting.sales-quotations.index', ['filter' => ['status' => SalesQuotationStatus::CANCELLED->value()]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 0));
        $this->get(route('accounting.sales-quotations.index', ['filter' => ['document_state' => 'with_items', 'item_description' => 'دهان']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1));
        $this->get(route('accounting.sales-quotations.index', ['filter' => ['validity' => 'expired']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 0));
    }

    public function test_a_quotation_is_created_with_a_weight_line_priced_from_the_party(): void
    {
        $this->get(route('accounting.sales-quotations.add'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/SalesQuotation/Add')
                ->where('quotation.items', [])
                ->has('taxOptions')
                ->has('referenceNo'));

        $quotation = $this->storedQuotation();
        $line = $quotation->items()->with('pieces')->sole();

        // السعر من قائمة الطرف، والمبلغ بالوزن الفعلي: 15 × 20.
        $this->assertSame(20.0, (float) $line->unit_price);
        $this->assertSame(15.0, (float) $line->quantity);
        $this->assertSame(14.4, (float) $line->total_weight);
        $this->assertSame(300.0, (float) $line->line_total);
        $this->assertCount(1, $line->pieces);
        $this->assertSame(300.0, (float) $quotation->total_amount);
        $this->assertSame(SalesQuotationStatus::DRAFT, $quotation->status);
    }

    public function test_a_new_main_item_without_sub_items_is_refused(): void
    {
        $line = $this->weightLine('20');
        $line['pieces'] = [];

        $this->post(route('accounting.sales-quotations.store'), $this->data([$line]))->assertSessionHas('error');
        $this->assertSame(0, SalesQuotation::query()->count());
    }

    public function test_the_edit_page_keeps_line_and_piece_ids_on_save(): void
    {
        $quotation = $this->storedQuotation();
        $line = $quotation->items()->with('pieces')->sole();

        $response = $this->get(route('accounting.sales-quotations.edit', $quotation->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/SalesQuotation/Edit')
                ->where('record.reference_no', $quotation->reference_no)
                ->where('record.editable', true)
                ->where('quotation.items.0.id', $line->id)
                ->where('quotation.items.0.pricing_type', 'weight')
                ->where('quotation.items.0.pieces.0.id', $line->pieces->first()->id)
                ->has('quotation.items.0.pieces', 1));

        $items = $response->viewData('page')['props']['quotation']['items'];
        $items[0]['actual_weight'] = 16;

        $this->put(route('accounting.sales-quotations.update', $quotation->id), $this->data($items))->assertSessionHas('success');

        $saved = $quotation->items()->with('pieces')->sole();
        $this->assertSame($line->id, $saved->id);
        $this->assertSame($line->pieces->first()->id, $saved->pieces->first()->id);
        $this->assertSame(320.0, (float) $saved->line_total);

        // بلا وزن فعلي يُحفظ السطر بمبلغ صفر (قرار صاحب النظام).
        $items[0]['actual_weight'] = null;
        $this->put(route('accounting.sales-quotations.update', $quotation->id), $this->data($items))->assertSessionHas('success');
        $this->assertSame(0.0, (float) $quotation->items()->sole()->line_total);
    }

    public function test_approve_convert_cancel_and_delete(): void
    {
        $quotation = $this->storedQuotation();
        $items = $this->get(route('accounting.sales-quotations.edit', $quotation->id))->viewData('page')['props']['quotation']['items'];

        $this->post(route('accounting.sales-quotations.approve', $quotation->id), $this->data($items))->assertSessionHas('success');
        $this->assertSame(SalesQuotationStatus::APPROVED, $quotation->refresh()->status);

        $order = null;
        $this->post(route('accounting.sales-quotations.convert', $quotation->id))
            ->assertRedirect()
            ->assertSessionHas('success');
        $order = SalesOrder::query()->where('sales_quotation_id', $quotation->id)->sole();
        $this->assertCount(1, $order->items()->sole()->pieces);

        $this->get(route('accounting.sales-quotations.edit', $quotation->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('record.can_convert', false)
                ->where('record.sales_order.id', $order->id));

        // عرض له طلبية لا يُلغى ولا يُحذف.
        $this->post(route('accounting.sales-quotations.cancel', $quotation->id))->assertSessionHas('error');
        $this->delete(route('accounting.sales-quotations.destroy', $quotation->id))->assertSessionHas('error');

        $draft = $this->storedQuotation();
        $this->post(route('accounting.sales-quotations.cancel', $draft->id))->assertSessionHas('success');
        $this->assertSame(SalesQuotationStatus::CANCELLED, $draft->refresh()->status);

        $other = $this->storedQuotation();
        $this->delete(route('accounting.sales-quotations.destroy', $other->id))
            ->assertRedirect(route('accounting.sales-quotations.index'))
            ->assertSessionHas('success');
        $this->assertSoftDeleted($other);
    }

    public function test_info_and_pdf(): void
    {
        $quotation = $this->storedQuotation();

        $this->getJson(route('accounting.sales-quotations.info', $quotation->id))->assertOk();
        $this->get(route('accounting.sales-quotations.pdf', $quotation->id))->assertOk();
    }

    public function test_each_action_needs_its_permission(): void
    {
        $quotation = $this->storedQuotation();

        $this->signIn(['Sales Quotation View List']);
        $this->get(route('accounting.sales-quotations.index'))->assertOk();
        $this->get(route('accounting.sales-quotations.add'))->assertForbidden();
        $this->post(route('accounting.sales-quotations.store'), $this->data())->assertForbidden();
        $this->get(route('accounting.sales-quotations.edit', $quotation->id))->assertForbidden();
        $this->put(route('accounting.sales-quotations.update', $quotation->id), $this->data())->assertForbidden();
        $this->post(route('accounting.sales-quotations.approve', $quotation->id), $this->data())->assertForbidden();
        $this->post(route('accounting.sales-quotations.convert', $quotation->id))->assertForbidden();
        $this->post(route('accounting.sales-quotations.cancel', $quotation->id))->assertForbidden();
        $this->delete(route('accounting.sales-quotations.destroy', $quotation->id))->assertForbidden();
        $this->get(route('accounting.sales-quotations.pdf', $quotation->id))->assertForbidden();
        $this->getJson(route('accounting.items-grid.item', ['document' => 'sales-quotation', 'product_id' => $this->product('DEMO-1025')->id]))->assertForbidden();

        $this->signIn([]);
        $this->get(route('accounting.sales-quotations.index'))->assertForbidden();
    }

    /* ---------- سطر مجموعة السعر (المرحلة 2): «الوبين — أبيض» وقطعه ألوان مقاطعه ---------- */

    /**
     * @return array{white: CategoryFinish, mesh: CategoryFinish, whiteW45: Product, meshW45: Product}
     */
    private function alpineGroups(): array
    {
        $alpine = Category::create(['slug' => 'alpine-'.Str::lower(Str::random(5)), 'status' => 1, 'pricing_type' => 'weight']);
        $alpine->translateOrNew('ar')->name = 'الوبين';
        $alpine->save();
        $white = CategoryFinish::create(['category_id' => $alpine->id, 'finish_id' => Finish::create(['name' => 'أبيض', 'is_active' => true])->id, 'is_active' => true]);
        $mesh = CategoryFinish::create(['category_id' => $alpine->id, 'finish_id' => Finish::create(['name' => 'ميش', 'is_active' => true])->id, 'is_active' => true]);
        $variation = function (string $name, CategoryFinish $group): Product {
            $product = new Product(['slug' => 'v-'.Str::lower(Str::random(8)), 'base_price' => 0, 'pricing_type' => 'weight', 'weight_per_meter' => 1.25, 'length' => 6.5, 'category_finish_id' => $group->id, 'finish_id' => $group->finish_id]);
            $product->save();
            $product->translateOrNew('ar')->name = $name;
            $product->save();

            return $product;
        };

        return ['white' => $white, 'mesh' => $mesh, 'whiteW45' => $variation('ثابت مجرين W45 - أبيض', $white), 'meshW45' => $variation('ثابت مجرين W45 - ميش', $mesh)];
    }

    private function groupLine(CategoryFinish $group, array $pieces, ?float $actual = null): array
    {
        return [
            '_key' => 'row-g', 'row_type' => 'item', 'product_id' => null, 'category_finish_id' => $group->id,
            'product_label' => 'الوبين — أبيض', 'pricing_type' => 'weight', 'quantity' => 1, 'actual_weight' => $actual,
            'unit_price' => '20', 'discount_mode' => 'rate', 'discount_rate' => 0, 'discount_amount' => 0, 'collapsed' => false,
            'pieces' => array_map(fn (array $piece) => ['_key' => 'p-'.$piece[0]->id, 'product_id' => $piece[0]->id, 'piece_count' => $piece[1], 'piece_length' => 6.5, 'weight_per_meter' => 1.25], $pieces),
        ];
    }

    public function test_a_price_group_line_saves_with_its_colour_pieces_and_reopens(): void
    {
        ['white' => $white, 'whiteW45' => $whiteW45] = $this->alpineGroups();

        $this->post(route('accounting.sales-quotations.store'), $this->data([$this->groupLine($white, [[$whiteW45, 2]], 16)]))
            ->assertSessionHas('success');

        $line = SalesQuotation::query()->latest('id')->firstOrFail()->items()->with('pieces')->sole();
        $this->assertNull($line->product_id);
        $this->assertSame($white->id, (int) $line->category_finish_id);
        $this->assertSame('الوبين — أبيض', $line->description);
        // الوزن الكلي من القطع (2 × 6.5 × 1.25)، والمبلغ بالوزن الفعلي × سعر الكيلو.
        $this->assertEquals(16.25, (float) $line->total_weight);
        $this->assertEquals(320, (float) $line->line_total);
        $this->assertSame([$whiteW45->id], $line->pieces->pluck('product_id')->map(fn ($id) => (int) $id)->all());

        $this->get(route('accounting.sales-quotations.edit', $line->sales_quotation_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('quotation.items.0.product_id', null)
                ->where('quotation.items.0.category_finish_id', $white->id)
                ->where('quotation.items.0.product_label', 'الوبين — أبيض')
                ->where('quotation.items.0.pricing_type', 'weight')
                ->where('quotation.items.0.has_sub_products', true)
                ->where('quotation.items.0.pieces.0.product_id', $whiteW45->id));
    }

    public function test_a_price_group_line_takes_only_its_colour_and_needs_pieces(): void
    {
        ['white' => $white, 'meshW45' => $meshW45] = $this->alpineGroups();

        $this->post(route('accounting.sales-quotations.store'), $this->data([$this->groupLine($white, [[$meshW45, 1]])]))
            ->assertSessionHas('error');
        $this->post(route('accounting.sales-quotations.store'), $this->data([$this->groupLine($white, [])]))
            ->assertSessionHas('error');

        $this->assertSame(0, SalesQuotation::query()->count());
    }
}
