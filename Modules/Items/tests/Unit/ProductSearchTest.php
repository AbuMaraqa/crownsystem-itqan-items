<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Brand;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Support\CategorySearch;
use Modules\Items\Support\ProductSearch;
use Modules\Items\Support\Search\ItemsSearchIndex;
use Modules\Items\Support\Search\SearchCapabilities;
use Modules\Items\Support\Search\SearchFilters;
use Modules\Items\Support\Search\SearchQuery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * البحث في الاصناف: فهرس البحث، والمطابقة بلا فرق في الهمزات وبأي ترتيب
 * للكلمات وفي كل الحقول، والترتيب بالاقرب، والتصفية، وتصحيح لغة لوحة المفاتيح.
 *
 * داخل معاملة الاختبار لا يرى InnoDB الصفوف في فهرس FULLTEXT، فيُبحث هنا بـ
 * LIKE على الفهرس نفسه (FullTextSearchTest يغطي FULLTEXT).
 */
class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private Category $aluminum;

    private Product $white;

    private Product $profile;

    private Product $door;

    protected function setUp(): void
    {
        parent::setUp();

        SearchCapabilities::forget();
        SearchCapabilities::withoutFullText();

        $user = User::factory()->create();
        $permissions = ['Product View List', 'Product Create', 'Product Update', 'Category Create', 'Category Update', 'Product Delete', 'Product Info', 'Product View'];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        $this->aluminum = $this->category('ألمنيوم سبير', '00301');
        $this->white = $this->product('المنيوم سبير أبيض', '00301001', 'SAP-W', 'weight');
        $this->profile = $this->product('ثابت 3 مجاري K4593', '00301002', '809712', 'weight', ['main_product_id' => $this->white->id]);
        $this->door = $this->product('الباب السحاب دَرفتين', 'D-2000', 'D2000', 'simple', category: $this->category('أبواب', '00500'));

        $this->flushIndex();
    }

    private function category(string $name, ?string $code, ?int $parentId = null): Category
    {
        $category = Category::create(['slug' => 'cat-'.uniqid(), 'code' => $code, 'status' => 1, 'parent_id' => $parentId]);
        $category->translateOrNew('ar')->name = $name;
        $category->save();

        return $category;
    }

    private function product(string $name, string $sku, ?string $barcode, string $pricingType, array $attributes = [], ?Category $category = null): Product
    {
        $product = new Product(array_merge([
            'slug' => Str::slug($sku).'-'.Str::random(6),
            'sku' => $sku,
            'barcode' => $barcode,
            'base_price' => 0,
            'pricing_type' => $pricingType,
            'is_active' => 1,
        ], $attributes));

        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();
        $product->categories()->attach(($category ?? $this->aluminum)->id);

        return $product;
    }

    /**
     * ما يُكتب في آخر الطلب، يُكتب هنا فوراً.
     */
    private function flushIndex(): void
    {
        app(ItemsSearchIndex::class)->flush();
    }

    /**
     * @return array<int, int>
     */
    private function search(string $term, ?SearchFilters $filters = null): array
    {
        return ProductSearch::find($term, 20, $filters)->ids;
    }

    public function test_the_index_document_holds_every_searchable_field(): void
    {
        $document = DB::table('items_product_search')->where('product_id', $this->door->id)->value('document');

        foreach ([' الباب ', ' باب ', ' السحاب ', ' سحاب ', ' درفتين ', ' d ', ' 2000 ', ' d2000 ', ' ابواب ', ' 00500 '] as $word) {
            $this->assertStringContainsString($word, $document);
        }

        // التصنيف موحَّداً ايضاً.
        $this->assertStringContainsString(' المنيوم ', DB::table('items_categories')->where('id', $this->aluminum->id)->value('search_text'));
    }

    public function test_spelling_variants_and_word_order_do_not_matter(): void
    {
        $this->assertSame($this->white->id, $this->search('ابيض المنيوم')[0]);
        $this->assertSame($this->white->id, $this->search('ألمنيوم سبير ابيض')[0]);
        // بلا "ال" وبلا تشكيل، وبادئة الكلمة تكفي.
        $this->assertSame([$this->door->id], $this->search('باب سحا درفت'));
    }

    public function test_items_are_found_by_category_brand_and_code_parts(): void
    {
        $brand = Brand::create(['slug' => 'crown-'.uniqid(), 'created_by' => auth()->id()]);
        $brand->translateOrNew('ar')->name = 'كراون';
        $brand->save();
        $this->door->update(['brand_id' => $brand->id]);
        $this->flushIndex();

        $this->assertContains($this->door->id, $this->search('أبواب'));
        $this->assertSame([$this->door->id], $this->search('كراون'));
        // جزء الرمز المختلط: K4593 ← 4593.
        $this->assertSame([$this->profile->id], $this->search('4593'));
        // الرقم بالارقام الهندية.
        $this->assertSame($this->profile->id, $this->search('٠٠٣٠١٠٠٢')[0]);
    }

    public function test_the_exact_code_ranks_first(): void
    {
        $this->product('صنف رقمه في اسمه 00301001 مكرر', 'X-1', null, 'simple');
        $this->flushIndex();

        $ids = $this->search('00301001');

        $this->assertSame($this->white->id, $ids[0]);
        $this->assertSame($this->door->id, $this->search('D-2000')[0]);
        $this->assertSame($this->door->id, $this->search('d2000')[0]);
    }

    public function test_a_word_inside_a_word_is_found_when_nothing_else_matches(): void
    {
        // "نيوم" وسط "المنيوم": لا تطابق بداية كلمة، فيُبحث وسط الكلمات.
        $result = ProductSearch::find('نيوم سبي', 20);

        $this->assertContains($this->white->id, $result->ids);
        $this->assertFalse($result->approximate);
    }

    public function test_partial_matches_are_shown_when_no_item_has_every_word(): void
    {
        $result = ProductSearch::find('سحاب زجاج', 20);

        $this->assertSame([$this->door->id], $result->ids);
        $this->assertTrue($result->approximate);
    }

    public function test_a_query_typed_with_the_wrong_keyboard_language_is_corrected(): void
    {
        // "hgfhf" هي "الباب" على لوحة المفاتيح الانجليزية.
        $result = ProductSearch::find('hgfhf', 20);

        $this->assertSame([$this->door->id], $result->ids);
        $this->assertSame('hgfhf', $result->correctedFrom);
        $this->assertSame('الباب', $result->query->raw);
    }

    public function test_filters_are_applied_inside_the_search(): void
    {
        $this->assertSame([], $this->search('الباب', new SearchFilters(pricingType: 'weight')));
        $this->assertSame([$this->door->id], $this->search('الباب', new SearchFilters(pricingType: 'simple')));

        $this->door->update(['is_active' => 0]);
        $this->assertSame([], $this->search('الباب', new SearchFilters(active: true)));

        // الصنف الفرعي يتبع تصنيف صنفه الرئيسي.
        $this->profile->categories()->detach();
        $this->assertSame([$this->profile->id], $this->search('مجاري', new SearchFilters(categoryIds: [$this->aluminum->id])));
    }

    public function test_the_index_follows_renames_of_the_item_and_its_category(): void
    {
        $this->door->translateOrNew('ar')->name = 'شباك جرار';
        $this->door->save();
        $this->flushIndex();

        $this->assertSame([$this->door->id], $this->search('جرار'));
        $this->assertSame([], $this->search('درفتين'));

        $category = $this->door->categories()->first();
        $category->translateOrNew('ar')->name = 'نوافذ';
        $category->save();
        $this->flushIndex();

        $this->assertSame([$this->door->id], $this->search('نوافذ'));

        $this->door->delete();
        $this->flushIndex();

        // المحذوف يخرج من الفهرس.
        $this->assertFalse(DB::table('items_product_search')->where('product_id', $this->door->id)->exists());
        $this->assertSame([], $this->search('جرار'));
    }

    public function test_dropdown_options_and_sub_item_options_use_the_new_search(): void
    {
        $this->assertSame([$this->white->id], array_keys(ProductSearch::options('ابيض', 10, 'weight')));
        $this->assertSame([$this->profile->id], array_keys(ProductSearch::subProductOptions($this->white->id, 'مجارى')));
        $this->assertSame([$this->aluminum->id], CategorySearch::search(SearchQuery::from('المنيوم'))->pluck('id')->all());
    }

    /**
     * جدول شجرة الاصناف كما تطلبه الصفحة.
     *
     * @return array<string, mixed>
     */
    private function treeRows(array $query): array
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        return $this->getJson(route('products.tree.rows', $query))->assertOk()->json();
    }

    public function test_the_items_tree_highlights_matches_and_explains_category_matches(): void
    {
        $result = $this->treeRows(['q' => 'ابواب']);
        $rows = collect($result['rows'])->keyBy('key');

        // الفئة المطابقة اولاً، ثم الصنف الذي طابق بتصنيفه مع ذكر ذلك.
        $this->assertTrue($rows->has('c'.$this->door->categories()->first()->id));
        $this->assertStringContainsString('<mark', $rows['c'.$this->door->categories()->first()->id]['html']['name']);
        $this->assertStringContainsString('أبواب', (string) $rows['p'.$this->door->id]['matched_in']);

        $this->assertSame(2, $result['summary']['count']);

        // الصنف الفرعي تحت صنفه الرئيسي، والرئيسي صف سياق لا يُعدّ نتيجة.
        $rows = $this->treeRows(['q' => 'مجاري'])['rows'];
        $this->assertSame(['p'.$this->white->id, 'p'.$this->profile->id], array_column($rows, 'key'));
        $this->assertTrue($rows[0]['is_context']);
        $this->assertTrue($rows[1]['is_sub']);
    }

    public function test_the_items_tree_filters_and_keyboard_open(): void
    {
        // تصفية النوع تُخرج اصناف الوزن.
        $this->assertSame([], $this->treeRows(['q' => 'المنيوم', 'type' => 'simple'])['rows']);

        // الصفحة بلا بحث في رابطها تبدأ بلا تصفية، وبدفعات من 50 صفاً.
        $this->get(route('products.tree'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pageSize', 50)
                ->where('filters.q', '')
                ->where('filters.type', '')
                ->where('filters.status', '')
                ->etc());

        // فتح صف الصنف يفتح بطاقته.
        $this->getJson(route('products.tree.items.edit', $this->door->id))->assertOk()->assertJsonPath('id', $this->door->id);

        // البحث داخل الفئة المختارة وحدها.
        $this->assertNotSame([], $this->treeRows(['q' => 'سحاب'])['rows']);
        $this->assertSame([], $this->treeRows(['q' => 'سحاب', 'category' => $this->aluminum->id, 'inside' => 1])['rows']);
    }

    public function test_the_items_list_table_searches_every_field_by_relevance(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $ids = fn (string $term): array => collect($this->get(route('products.index', ['filter' => ['search' => $term]]))
            ->assertOk()
            ->viewData('page')['props']['table']['data'])->pluck('id')->all();

        $this->assertSame([$this->door->id], $ids('ابواب'));
        // الصنف ينتمي الى تصنيفه فقط: المقطع القديم يظهر بنفسه، لا صنفه الرئيسي.
        $this->assertSame([$this->profile->id], $ids('مجاري'));
    }
}
