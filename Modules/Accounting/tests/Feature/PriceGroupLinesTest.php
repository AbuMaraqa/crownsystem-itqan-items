<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\PurchaseInvoiceItem;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Accounting\Entities\PurchaseReturnItem;
use Modules\Accounting\Entities\SalesInvoiceItem;
use Modules\Accounting\Entities\SalesOrderItem;
use Modules\Accounting\Entities\SalesQuotationItem;
use Modules\Accounting\Entities\SalesReturnItem;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\CategoryFinishPrice;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Support\Search\ItemsSearchIndex;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * سطر مجموعة السعر في المستندات (المرحلة 2): السطر الرئيسي لصنف الوزن هو (التصنيف × اللون)
 * بلا صنف، وقطعه متغيّرات ذلك اللون. التصميم: docs/superpowers/specs/2026-11-22-catalog-phase-2-document-lines-design.md
 */
class PriceGroupLinesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<class-string> */
    private const LINE_MODELS = [
        SalesQuotationItem::class, SalesOrderItem::class, SalesInvoiceItem::class, SalesReturnItem::class,
        PurchaseOrderItem::class, PurchaseInvoiceItem::class, PurchaseReturnItem::class,
    ];

    private Category $alpine;

    private CategoryFinish $white;

    private CategoryFinish $mesh;

    private Product $profile;

    private Product $whiteW45;

    private Product $meshW45;

    private Product $handle;

    private int $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');
        config(['broadcasting.default' => 'null']);
    }

    /** الوبين بلونين، ومقطع W45 بلونيه، ومقبض بالكمية، وزبون على قائمة «نقدي». */
    private function catalogue(): void
    {
        $this->alpine = Category::create(['slug' => 'alpine-'.Str::lower(Str::random(5)), 'status' => 1, 'pricing_type' => 'weight']);
        $this->alpine->translateOrNew('ar')->name = 'الوبين';
        $this->alpine->save();

        $finish = fn (string $name) => Finish::create(['name' => $name, 'is_active' => true]);
        $this->white = CategoryFinish::create(['category_id' => $this->alpine->id, 'finish_id' => $finish('أبيض')->id, 'is_active' => true]);
        $this->mesh = CategoryFinish::create(['category_id' => $this->alpine->id, 'finish_id' => $finish('ميش')->id, 'is_active' => true]);

        $this->profile = $this->product('ثابت مجرين W45', ['pricing_type' => 'weight', 'weight_per_meter' => 1.25, 'length' => 6.5]);
        $this->whiteW45 = $this->product('ثابت مجرين W45 - أبيض', ['pricing_type' => 'weight', 'weight_per_meter' => 1.25, 'length' => 6.5, 'parent_id' => $this->profile->id, 'category_finish_id' => $this->white->id, 'finish_id' => $this->white->finish_id]);
        $this->meshW45 = $this->product('ثابت مجرين W45 - ميش', ['pricing_type' => 'weight', 'weight_per_meter' => 1.25, 'length' => 6.5, 'parent_id' => $this->profile->id, 'category_finish_id' => $this->mesh->id, 'finish_id' => $this->mesh->finish_id]);
        $this->handle = $this->product('مقبض الوبين', ['pricing_type' => 'simple', 'base_price' => 7]);

        $cash = PriceList::create(['name' => 'نقدي', 'is_active' => true, 'sort_order' => 1]);
        CategoryFinishPrice::create(['category_finish_id' => $this->white->id, 'price_list_id' => $cash->id, 'sell_price' => '20', 'buy_price' => '15']);
        CategoryFinishPrice::create(['category_finish_id' => $this->mesh->id, 'price_list_id' => $cash->id, 'sell_price' => '24', 'buy_price' => '18']);

        $customer = User::factory()->create();
        $customer->forceFill(['price_list_id' => $cash->id])->save();
        $this->party = (int) $customer->id;

        app(ItemsSearchIndex::class)->flush();
    }

    private function product(string $name, array $attributes): Product
    {
        $product = new Product(['slug' => 'p-'.Str::lower(Str::random(8)), 'base_price' => 0, ...$attributes]);
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();
        $product->categories()->attach($this->alpine->id);

        return $product;
    }

    private function signIn(array $permissions = ['Sales Quotation Create']): void
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);
    }

    private function grid(string $endpoint, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(route('accounting.items-grid.'.$endpoint, ['document' => 'sales-quotation', ...$query]));
    }

    public function test_one_search_finds_price_groups_quantity_items_and_colours(): void
    {
        $this->catalogue();
        $this->signIn();

        $results = collect($this->grid('products', ['q' => 'الوبين'])->assertOk()->json());

        $groups = $results->where('kind', 'group');
        $this->assertSame([$this->white->id, $this->mesh->id], $groups->pluck('id')->sort()->values()->all());
        $this->assertContains('الوبين — أبيض', $groups->pluck('label')->all());
        $this->assertTrue($results->contains(fn (array $result) => $result['kind'] === 'item' && $result['id'] === $this->handle->id));
        // المقطع نفسه لا يُباع: ألوانه هي ما يُختار.
        $this->assertFalse($results->contains(fn (array $result) => $result['kind'] === 'item' && $result['id'] === $this->profile->id));

        $colours = collect($this->grid('products', ['q' => 'W45'])->json())->where('kind', 'item');
        $this->assertSame([$this->whiteW45->id, $this->meshW45->id], $colours->pluck('id')->sort()->values()->all());
        $this->assertSame('الوبين — أبيض', $colours->firstWhere('id', $this->whiteW45->id)['group_label']);
    }

    /**
     * مواد أمر الانتاج تُبحث كاصناف طلبيات البيع والشراء: المقطع بألوانه، والاسم يحمل مجموعة اللون.
     */
    public function test_production_materials_are_searched_like_the_order_items(): void
    {
        $this->catalogue();
        $this->signIn(['Production Order Create']);

        $results = collect($this->getJson(route('accounting.production-orders.materials', ['q' => 'W45']))->assertOk()->json());

        $this->assertSame([$this->whiteW45->id, $this->meshW45->id], $results->pluck('value')->sort()->values()->all());
        $this->assertStringContainsString('الوبين — أبيض', $results->firstWhere('value', $this->whiteW45->id)['label']);
    }

    public function test_inside_a_group_line_only_its_colour_is_offered(): void
    {
        $this->catalogue();
        $this->signIn();

        $ids = collect($this->grid('piece-outputs', ['q' => 'W45', 'group_id' => $this->white->id])->assertOk()->json())->pluck('id')->all();

        $this->assertSame([$this->whiteW45->id], $ids);
    }

    public function test_a_group_and_its_colours_carry_the_party_kg_price(): void
    {
        $this->catalogue();
        $this->signIn();

        $this->grid('group', ['group_id' => $this->mesh->id, 'party_id' => $this->party])
            ->assertOk()
            ->assertJsonPath('id', $this->mesh->id)
            ->assertJsonPath('label', 'الوبين — ميش')
            ->assertJsonPath('pricing_type', 'weight')
            ->assertJsonPath('unit_price', '24.0000');

        $this->grid('item', ['product_id' => $this->whiteW45->id, 'party_id' => $this->party])
            ->assertOk()
            ->assertJsonPath('group.id', $this->white->id)
            ->assertJsonPath('group.label', 'الوبين — أبيض')
            ->assertJsonPath('group.unit_price', '20.0000')
            ->assertJsonPath('weight_per_meter', '1.2500');

        // تغيير الطرف يعيد تسعير سطور المجموعات ايضاً.
        $this->grid('group-prices', ['ids' => [$this->white->id, $this->mesh->id], 'party_id' => $this->party])
            ->assertOk()
            ->assertJsonPath((string) $this->white->id, '20.0000')
            ->assertJsonPath((string) $this->mesh->id, '24.0000');
    }

    public function test_document_lines_carry_a_price_group(): void
    {
        foreach (self::LINE_MODELS as $class) {
            $line = new $class;

            $this->assertTrue(Schema::hasColumn($line->getTable(), 'category_finish_id'), $line->getTable());
            $this->assertTrue($line->isFillable('category_finish_id'), $class);
            $this->assertInstanceOf(BelongsTo::class, $line->categoryFinish());
            $this->assertInstanceOf(CategoryFinish::class, $line->categoryFinish()->getRelated());
        }
    }
}
