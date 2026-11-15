<?php

namespace Modules\Items\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\ItemTypeProfile;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ProfileThickness;
use Modules\Items\Services\MainProductLinker;
use Modules\Items\Support\ItemCode;
use Modules\Items\Support\Search\ItemsSearchIndex;
use Modules\Items\Support\Search\SearchCapabilities;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * شجرة الاصناف في لوحة Vue كما في النظام القديم: كل عقدة برقمها، التصنيف ← الصنف الرئيسي
 * ← اصنافه الفرعية، وجدول العقدة، والبحث، وبطاقة الصنف وبطاقة الفئة، ونافذة الكمية.
 * (كانت ItemsTreePageTest على صفحة Livewire؛ كل حالاتها هنا عبر HTTP.)
 */
class ItemTreePagesTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Product $main;

    private Product $sub;

    private Product $door;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');
        SearchCapabilities::forget();
        SearchCapabilities::withoutFullText();
        $this->withoutMiddleware([\Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class, \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class]);
        $this->signIn(['Product View List', 'Product Create', 'Product Update', 'Category Create', 'Category Update']);

        $this->category = $this->category('المنيوم الوبين', '00009');

        $this->main = $this->product('المنيوم الوبين ابيض', '00001', 'White', 'weight');
        $this->sub = $this->product('ثابت مجرين W45', '00001001', '809712', 'weight', ['main_product_id' => $this->main->id, 'length' => 6, 'weight_per_meter' => 0.444]);
        $this->door = $this->product('باب 2000 دفتين', 'D-2000', 'D2000', 'simple');
    }

    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $this->actingAs($user);

        return $user;
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
        ], $attributes));

        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();
        $product->categories()->attach(($category ?? $this->category)->id);

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function rows(array $query): array
    {
        return $this->getJson(route('products.tree.rows', $query))->assertOk()->json();
    }

    private function saveItem(array $data, ?int $productId = null): \Illuminate\Testing\TestResponse
    {
        $data = ['item_type' => 'goods', 'pricing_type' => 'simple', 'base_price' => 0, ...$data];

        return $this->from(route('products.tree'))->{$productId ? 'put' : 'post'}(
            $productId ? route('products.tree.items.update', $productId) : route('products.tree.items.store'),
            $data,
        );
    }

    /**
     * البطاقة كما تُفتح (state) مع ما يرسله النموذج فوقها.
     *
     * @return array<string, mixed>
     */
    private function editState(int $productId): array
    {
        return $this->getJson(route('products.tree.items.edit', $productId))->assertOk()->json('state');
    }

    public function test_the_page_renders_the_roots_and_needs_the_permission(): void
    {
        $this->get(route('products.tree', ['q' => 'باب', 'type' => 'simple']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/ItemTree/Index')
                ->where('roots.0.code', '00009')
                ->where('roots.0.name', 'المنيوم الوبين')
                ->where('filters.q', 'باب')
                ->where('filters.type', 'simple')
                ->where('filters.status', ''));

        $this->signIn([]);
        $this->get(route('products.tree'))->assertForbidden();
        $this->getJson(route('products.tree.rows', ['category' => $this->category->id]))->assertForbidden();
    }

    public function test_the_tree_opens_a_category_then_a_main_item_on_its_sub_items(): void
    {
        $node = $this->getJson(route('products.tree.children', $this->category->id))->assertOk()->json();

        // الصنف الفرعي لا يظهر في مستوى التصنيف بل داخل صنفه الرئيسي.
        $this->assertSame([$this->main->id, $this->door->id], array_column($node['products'], 'id'));
        $this->assertTrue($node['products'][0]['has_sub_products']);
        $this->assertFalse($node['has_more']);

        // الجدول: الفئة نفسها صف الرأس ثم اصنافها.
        $rows = $this->rows(['category' => $this->category->id])['rows'];
        $this->assertSame(['c'.$this->category->id, 'p'.$this->main->id, 'p'.$this->door->id], array_column($rows, 'key'));
        $this->assertTrue($rows[0]['is_header']);
        $this->assertSame('00009', $rows[0]['sku']);

        $pieces = $this->getJson(route('products.tree.pieces', $this->main->id))->assertOk()->json();
        $this->assertSame([$this->sub->id], array_column($pieces, 'id'));

        // الجدول: الصنف الرئيسي اولاً ثم اصنافه الفرعية.
        $rows = $this->rows(['product' => $this->main->id])['rows'];
        $this->assertSame([$this->main->id, $this->sub->id], array_column($rows, 'id'));
        $this->assertTrue($rows[0]['is_header']);
        $this->assertFalse($rows[1]['is_header']);
    }

    public function test_a_category_shows_its_items_in_batches(): void
    {
        foreach (range(1, 51) as $index) {
            $this->product('صنف '.$index, 'Z'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), null, 'simple');
        }

        $first = $this->getJson(route('products.tree.children', $this->category->id))->json();
        $this->assertCount(50, $first['products']);
        $this->assertTrue($first['has_more']);

        $more = $this->getJson(route('products.tree.children', [$this->category->id, 'limit' => 100]))->json();
        $this->assertCount(53, $more['products']);
        $this->assertFalse($more['has_more']);

        $rows = $this->rows(['category' => $this->category->id]);
        $this->assertTrue($rows['has_more']);
        $this->assertCount(51, $rows['rows']);
    }

    public function test_a_category_and_its_twin_item_are_one_node(): void
    {
        $sapir = $this->category('المنيوم سبير sapir 1200', '00301');
        $twin = $this->product('المنيوم سبير sapir 1200', '00301', 'SAPIR', 'weight', [], $sapir);
        $profile = $this->product('ثابت 3 مجاري K4593', '00301001', 'K4593', 'weight', ['main_product_id' => $twin->id], $sapir);
        $accessories = $this->category('اكسسوارات سبير', '00301002', $sapir->id);

        $node = $this->getJson(route('products.tree.children', $sapir->id))->json();

        // مقاطع التوأم ابناء الفئة مباشرة، والتوأم لا يتكرر عقدةً تحتها.
        $this->assertSame([$profile->id], array_column($node['products'], 'id'));
        $this->assertSame([$accessories->id], array_column($node['categories'], 'id'));

        // رأس الجدول هو التوأم (بباركوده)، ثم الفئات الفرعية ثم الاصناف.
        $rows = $this->rows(['category' => $sapir->id])['rows'];
        $this->assertSame(['p'.$twin->id, 'c'.$accessories->id, 'p'.$profile->id], array_column($rows, 'key'));
        $this->assertSame('SAPIR', $rows[0]['barcode']);

        // بطاقة صنف جديد في الفئة: تحت توأمها.
        $this->getJson(route('products.tree.items.create', ['category' => $sapir->id]))
            ->assertOk()
            ->assertJsonPath('state.main_product_id', $twin->id)
            ->assertJsonPath('state.pricing_type', 'weight');

        $this->saveItem(['name' => 'جنب 3 مجاري', 'weight_per_meter' => 0.9, 'main_product_id' => $twin->id, 'pricing_type' => 'weight', 'category_id' => $sapir->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('products.tree'));

        $this->assertSame('00301003', Product::query()->where('main_product_id', $twin->id)->latest('id')->value('sku'));

        // الدخول الى فئة من الجدول يفتحها في الشجرة مع آبائها.
        $this->getJson(route('products.tree.path', $accessories->id))->assertExactJson([$sapir->id, $accessories->id]);
    }

    public function test_searching_a_sub_item_shows_it_under_its_main_item(): void
    {
        $rows = $this->rows(['q' => '809712'])['rows'];

        $this->assertSame([$this->main->id, $this->sub->id], array_column($rows, 'id'));
        $this->assertSame(['00001', '00001001'], array_column($rows, 'sku'));
    }

    public function test_searching_finds_a_category_by_its_number(): void
    {
        $this->assertSame('c'.$this->category->id, $this->rows(['q' => '00009'])['rows'][0]['key']);
    }

    public function test_search_filters_and_scope(): void
    {
        // تصفية النوع تُخرج صنف الوزن، والفئات لا تظهر معها.
        $this->assertSame([], $this->rows(['q' => 'المنيوم', 'type' => 'simple'])['rows']);

        // البحث داخل الفئة المختارة وفروعها فقط.
        $other = $this->category('زجاج', '00011');
        $this->product('باب زجاج', '00011001', 'GL', 'simple', [], $other);
        $this->assertSame([], $this->rows(['q' => 'زجاج', 'category' => $this->category->id, 'inside' => 1])['rows']);
        $this->assertNotSame([], $this->rows(['q' => 'زجاج'])['rows']);

        $summary = $this->rows(['q' => 'باب'])['summary'];
        $this->assertGreaterThan(0, $summary['count']);
        $this->assertFalse($summary['has_more']);
    }

    public function test_a_new_sub_item_is_numbered_after_its_main_item(): void
    {
        // الصف المحدد صنف رئيسي: البطاقة الجديدة تحته وبالوزن.
        $this->getJson(route('products.tree.items.create', ['category' => $this->category->id, 'highlighted' => $this->main->id]))
            ->assertJsonPath('state.main_product_id', $this->main->id)
            ->assertJsonPath('state.pricing_type', 'weight');

        $this->saveItem([
            'name' => 'يو 3سم مع فرشاية K5950',
            'barcode' => '181004',
            'length' => 6,
            'weight_per_meter' => 0.5,
            'main_product_id' => $this->main->id,
            'pricing_type' => 'weight',
            'category_id' => $this->category->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('tree_saved');

        $created = Product::query()->where('barcode', '181004')->firstOrFail();

        $this->assertSame('00001002', $created->sku);
        $this->assertSame($this->main->id, $created->main_product_id);
        $this->assertSame('weight', $created->pricing_type);
        $this->assertSame('يو 3سم مع فرشاية K5950', $created->name);
        $this->assertTrue($created->categories()->whereKey($this->category->id)->exists());
    }

    public function test_a_new_item_in_a_category_is_numbered_after_the_category(): void
    {
        $this->getJson(route('products.tree.items.create', ['category' => $this->category->id]))
            ->assertJsonPath('state.main_product_id', null)
            ->assertJsonPath('state.pricing_type', 'simple');

        $this->saveItem(['name' => 'مقبض باب', 'barcode' => 'H-1', 'category_id' => $this->category->id])->assertSessionHasNoErrors();

        $created = Product::query()->where('barcode', 'H-1')->firstOrFail();

        $this->assertSame('00009001', $created->sku);
        $this->assertTrue($created->categories()->whereKey($this->category->id)->exists());
    }

    public function test_a_new_category_is_numbered_under_its_parent(): void
    {
        $this->from(route('products.tree'))
            ->post(route('products.tree.categories.store'), ['name' => 'سلك', 'parent_id' => $this->category->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('tree_saved.kind', 'category');

        $created = Category::query()->where('parent_id', $this->category->id)->firstOrFail();

        $this->assertSame('00009001', $created->code);
        $this->assertSame('سلك', $created->name);
        $this->assertSame([$this->category->id, $created->id], session('tree_saved.path'));

        // فئة جذرية: بعد اكبر رقم من خمس خانات في الفئات والاصناف.
        $this->post(route('products.tree.categories.store'), ['name' => 'زجاج'])->assertSessionHasNoErrors();

        $this->assertSame('00010', Category::query()->whereNull('parent_id')->latest('id')->value('code'));

        // تعديل الفئة: الاسم فقط.
        $this->getJson(route('products.tree.categories.show', $created->id))->assertJsonPath('parent.code', '00009');
        $this->put(route('products.tree.categories.update', $created->id), ['name' => 'سلك معدّل'])->assertSessionHasNoErrors();
        $this->assertSame('سلك معدّل', $created->fresh()->name);
        $this->assertSame('00009001', $created->fresh()->code);
    }

    public function test_items_and_categories_share_the_numbering(): void
    {
        $this->product('مقبض', '00009001', 'H-2', 'simple');
        $this->category('سلك', '00009002', $this->category->id);

        $this->assertSame('00009003', ItemCode::nextChild('00009'));
        $this->assertSame('00009003', Category::create(['slug' => 'auto-'.uniqid(), 'status' => 1, 'parent_id' => $this->category->id])->code);
    }

    public function test_the_item_card_edits_an_item(): void
    {
        $supplier = User::factory()->create();
        $supplier->assignRole(Role::findOrCreate('Supplier', 'web'));

        $card = $this->getJson(route('products.tree.items.edit', $this->sub->id))
            ->assertOk()
            ->assertJsonPath('state.sku', '00001001')
            ->assertJsonPath('state.main_product_id', $this->main->id)
            ->assertJsonPath('main_sku', '00001')
            ->json();

        $this->assertContains($supplier->id, array_column($card['options']['suppliers'], 'value'));

        $this->saveItem([
            ...$card['state'],
            'name' => 'ثابت مجرين W45 معدّل',
            'weight_per_meter' => 0.5,
            'shelf' => 'A3',
            'synonym_product_id' => $this->door->id,
            'supplier_ids' => [$supplier->id],
            'sku' => 'HACK',
        ], $this->sub->id)->assertSessionHasNoErrors();

        $this->sub->refresh();

        $this->assertSame('ثابت مجرين W45 معدّل', $this->sub->name);
        $this->assertSame(0.5, (float) $this->sub->weight_per_meter);
        $this->assertSame('A3', $this->sub->shelf);
        $this->assertSame($this->door->id, $this->sub->synonym_product_id);
        $this->assertSame([$supplier->id], $this->sub->suppliers()->pluck('users.id')->map(fn ($id): int => (int) $id)->all());
        // رقم الصنف لا يتغير بعد انشائه.
        $this->assertSame('00001001', $this->sub->sku);
    }

    public function test_an_item_cannot_be_its_own_synonym(): void
    {
        $this->saveItem([...$this->editState($this->door->id), 'synonym_product_id' => $this->door->id], $this->door->id)
            ->assertSessionHasErrors('synonym_product_id');
    }

    public function test_the_quantity_window_shows_the_item_and_its_sub_items(): void
    {
        // الرصيد لا يُكتب الا من دفتر المخزون، فيُضبط هنا مباشرة.
        $this->main->forceFill(['stock_quantity' => 3, 'stock_weight' => 3, 'min_limit_1' => 5])->saveQuietly();
        $this->sub->forceFill(['stock_quantity' => 40, 'stock_weight' => 120.5, 'min_limit_1' => 10])->saveQuietly();

        $rows = $this->getJson(route('products.tree.quantity', $this->main->id))->assertOk()->json();

        // سطر الصنف الرئيسي: عدد القطع من فروعه، والوزن كغمه هو (3) لا مجموع الوزن المحسوب.
        $this->assertSame([$this->main->id, $this->sub->id], array_column($rows, 'id'));
        $this->assertEquals([40, 40], array_column($rows, 'quantity'));
        $this->assertEquals([3, 120.5], array_column($rows, 'weight'));
        $this->assertSame([true, false], array_column($rows, 'is_total'));
        $this->assertSame([true, false], array_column($rows, 'is_low'));
    }

    public function test_a_quantity_item_cannot_belong_to_a_main_item(): void
    {
        $this->saveItem(['name' => 'مقبض', 'pricing_type' => 'simple', 'main_product_id' => $this->main->id])
            ->assertSessionHasErrors('main_product_id');
    }

    public function test_the_card_needs_the_permission_of_its_mode(): void
    {
        $this->signIn(['Product View List']);

        $this->getJson(route('products.tree.items.create'))->assertForbidden();
        $this->getJson(route('products.tree.items.edit', $this->door->id))->assertForbidden();
        $this->saveItem(['name' => 'ممنوع'])->assertForbidden();
        $this->saveItem(['name' => 'ممنوع'], $this->door->id)->assertForbidden();
        $this->post(route('products.tree.categories.store'), ['name' => 'ممنوع'])->assertForbidden();

        $this->assertFalse(Product::query()->whereTranslation('name', 'ممنوع')->exists());
    }

    /* ---------- البحث (من ProductSearchTest) ---------- */

    public function test_search_highlights_matches_and_explains_category_matches(): void
    {
        $doors = $this->category('أبواب', '00012');
        $item = $this->product('درفة خشب', '00012001', 'DW', 'simple', [], $doors);
        app(ItemsSearchIndex::class)->flush();

        $rows = collect($this->rows(['q' => 'ابواب'])['rows'])->keyBy('key');

        // الفئة المطابقة اولاً، ثم الصنف الذي طابق بتصنيفه مع ذكر ذلك.
        $this->assertTrue($rows->has('c'.$doors->id));
        $this->assertStringContainsString('<mark', $rows['c'.$doors->id]['html']['name']);
        $this->assertStringContainsString('أبواب', (string) $rows['p'.$item->id]['matched_in']);

        // الصنف الفرعي تحت صنفه الرئيسي، والرئيسي صف سياق لا يُعدّ نتيجة.
        $rows = $this->rows(['q' => 'مجرين'])['rows'];
        $this->assertSame(['p'.$this->main->id, 'p'.$this->sub->id], array_column($rows, 'key'));
        $this->assertTrue($rows[0]['is_context']);
        $this->assertTrue($rows[1]['is_sub']);
    }

    /* ---------- بطاقة الصنف: نوع الصنف والالمنيوم (من ItemTypeProfilesTest) ---------- */

    public function test_the_card_saves_the_profile_and_aluminium_fields(): void
    {
        $profile = $this->profile(true);
        $item = $this->product('بروفيل', 'P-1', null, 'weight');

        $state = $this->editState($item->id);
        $this->assertNull($state['item_type_profile_id']);

        $this->saveItem([
            ...$state,
            'item_type_profile_id' => $profile->id,
            'bundle_count' => 25,
            'profile_thickness' => 'thick',
            'is_open_profile' => 1,
            'area' => '1.25',
        ], $item->id)->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame($profile->id, $item->item_type_profile_id);
        $this->assertSame(25, (int) $item->bundle_count);
        $this->assertSame(ProfileThickness::THICK, $item->profile_thickness);
        $this->assertTrue($item->is_open_profile);
        $this->assertSame('1.2500', $item->area);
    }

    public function test_an_aluminium_profile_makes_the_item_by_weight(): void
    {
        $profile = $this->profile(true);
        $card = $this->getJson(route('products.tree.items.edit', $this->door->id))->json();

        // الواجهة تعرف نوع الالمنيوم فتغيّر النوع، والنموذج يفرضه عند الحفظ ايضاً.
        $option = collect($card['options']['profiles'])->firstWhere('value', $profile->id);
        $this->assertTrue($option['aluminium']);

        $this->saveItem([...$card['state'], 'item_type_profile_id' => $profile->id], $this->door->id)->assertSessionHasNoErrors();

        $this->assertSame('weight', $this->door->refresh()->pricing_type);
    }

    public function test_aluminium_fields_of_a_quantity_item_are_kept_and_emptied_ones_become_null(): void
    {
        $this->door->forceFill(['bundle_count' => 7])->save();

        // صنف كمية: حقول الالمنيوم لا تُحفظ ولو وصلت.
        $this->saveItem([...$this->editState($this->door->id), 'bundle_count' => 99], $this->door->id)->assertSessionHasNoErrors();
        $this->assertSame(7, (int) $this->door->refresh()->bundle_count);

        $weight = $this->product('وزن', 'W-1', null, 'weight', ['bundle_count' => 7, 'profile_thickness' => 'thin', 'is_open_profile' => false, 'area' => 2]);
        $state = $this->editState($weight->id);
        $this->assertSame(0, $state['is_open_profile']);

        $this->saveItem([...$state, 'bundle_count' => '', 'profile_thickness' => null, 'is_open_profile' => null, 'area' => ''], $weight->id)
            ->assertSessionHasNoErrors();

        $weight->refresh();
        $this->assertNull($weight->bundle_count);
        $this->assertNull($weight->profile_thickness);
        $this->assertNull($weight->is_open_profile);
        $this->assertNull($weight->area);
    }

    public function test_pieces_of_one_main_item_can_share_a_name(): void
    {
        $this->saveItem(['name' => $this->sub->name, 'pricing_type' => 'weight', 'main_product_id' => $this->main->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Product::query()->where('main_product_id', $this->main->id)->count());
    }

    /* ---------- التوأم بعد الاستيراد (من LegacyImportMainItemTest) ---------- */

    public function test_a_linked_twin_is_one_row_with_its_pieces(): void
    {
        $twinCategory = $this->category('رئيسي', 'M1');
        $twin = $this->product('رئيسي', 'M1', null, 'weight', [], $twinCategory);
        $this->product('مقطع 1', 'M1001', null, 'weight', ['main_product_id' => $twin->id], $twinCategory);
        $this->product('مقطع 2', 'M1002', null, 'weight', ['main_product_id' => $twin->id], $twinCategory);

        app(MainProductLinker::class)->link(dryRun: false, createFromCategories: true);

        $rows = $this->rows(['category' => $twinCategory->id])['rows'];

        $this->assertSame(['M1', 'M1001', 'M1002'], array_column($rows, 'sku'));
        $this->assertSame('product', $rows[0]['kind']);
    }

    private function profile(bool $aluminium): ItemTypeProfile
    {
        return ItemTypeProfile::create([
            'code' => 'type_'.Str::lower(Str::random(6)),
            'name' => 'نوع',
            'sub_item_mode' => $aluminium ? 'aluminium' : 'none',
        ]);
    }
}
