<?php

namespace Modules\Items\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Attribute;
use Modules\Items\Entities\AttributeValue;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\CategoryFinishPrice;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * تبويب «الألوان والمتغيرات» في بطاقة الصنف: لون صنف الوزن متغيّر يحمل مجموعة سعره (التصنيف × اللون)
 * ويأخذ مقاساته من الاب، ولون الاكسسوار يأتي من قيمة سمة «اللون». وما يُستخدم في مستند لا يُحذف.
 */
class ProductColoursTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['Product View List', 'Product Create', 'Product Update', 'Stock Limit Update'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');
        config(['broadcasting.default' => 'null']);

        foreach (self::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function signIn(array $permissions = self::ALL): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function category(string $name): Category
    {
        $category = Category::create(['slug' => 'cat-'.Str::lower(Str::random(8)), 'status' => 1, 'pricing_type' => 'weight']);
        $category->translateOrNew('ar')->name = $name;
        $category->save();

        return $category;
    }

    /** لون ومرآته في سمة «اللون»، كما يُنشئه النقل. */
    private function finish(string $name, ?string $hex = null): Finish
    {
        $value = new AttributeValue(['attribute_id' => Finish::colourAttributeId(), 'color_code' => $hex]);
        $value->translateOrNew('ar')->name = $name;
        $value->save();

        return Finish::create(['name' => $name, 'is_active' => true, 'attribute_value_id' => $value->id]);
    }

    private function group(Category $category, Finish $finish): CategoryFinish
    {
        return CategoryFinish::create(['category_id' => $category->id, 'finish_id' => $finish->id, 'is_active' => true]);
    }

    private function product(string $name, array $attributes = []): Product
    {
        $product = new Product(array_merge(['slug' => 'item-'.Str::lower(Str::random(8)), 'base_price' => 0, 'pricing_type' => 'simple'], $attributes));
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    /**
     * ما ترسله صفحة الاضافة (emptyProduct في productForm.ts).
     *
     * @return array<string, mixed>
     */
    private function newItem(array $overrides = []): array
    {
        return array_merge([
            'name' => 'ثابت مجرين W45', 'slug' => '', 'short_description' => '', 'description' => '', 'sku' => '', 'barcode' => '',
            'base_price' => '', 'max_limit' => '', 'min_limit_1' => '', 'min_limit_2' => '', 'category_id' => [],
            'removed_image' => [], 'removed_gallery' => [],
            'weight_per_meter' => '1.25', 'length' => '6.5', 'pricing_type' => 'weight', 'item_type_profile_id' => null,
            'bundle_count' => '', 'profile_thickness' => null, 'is_open_profile' => null, 'area' => '',
            'item_type' => 'goods', 'service_kind' => 'general', 'service_cost_source' => 'internal',
            'revenue_account_id' => null, 'cost_account_id' => null, 'site_area' => '',
            'brand_id' => null, 'unit_id' => null, 'tags' => [], 'labels' => [], 'manufacturers' => [], 'branches' => [],
            'addons' => [], 'accessories' => [], 'cutting_recipes' => [], 'variations' => [],
        ], $overrides);
    }

    /** صف لون جديد كما يبنيه تبويب الألوان. */
    private function colourRow(CategoryFinish $group, array $overrides = []): array
    {
        return array_merge([
            'id' => null, 'name' => '', 'slug' => '', 'sku' => '', 'barcode' => '', 'base_price' => '', 'is_active' => true,
            'category_finish_id' => $group->id, 'attribute_values' => [], 'category_id' => [],
            'max_limit' => '', 'min_limit_1' => '', 'min_limit_2' => '',
        ], $overrides);
    }

    private function store(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('products.store'), ['payload' => json_encode($data)]);
    }

    private function update(Product $product, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->put(route('products.update', $product->id), ['payload' => json_encode($data)]);
    }

    /** حالة صفحة التعديل كما تعيدها الصفحة، بعد دمج التعديلات. */
    private function editState(Product $product, array $overrides = []): array
    {
        $state = $this->get(route('products.edit', $product->id))->assertOk()->viewData('page')['props']['product'];
        unset($state['id'], $state['images'], $state['gallery_images'], $state['attributes']);
        $state['variations'] = array_map(fn (array $row) => array_diff_key($row, ['images' => 1, 'gallery_images' => 1]), $state['variations']);

        return array_merge($state, ['removed_image' => [], 'removed_gallery' => []], $overrides);
    }

    private function storedWeightItem(Category $category, array $groups): Product
    {
        $this->store($this->newItem([
            'category_id' => [$category->id],
            'variations' => array_map(fn (CategoryFinish $group) => $this->colourRow($group), $groups),
        ]))->assertSessionHasNoErrors();

        return Product::query()->whereNull('parent_id')->whereTranslation('name', 'ثابت مجرين W45')->firstOrFail();
    }

    public function test_a_weight_item_gets_one_variant_per_chosen_colour(): void
    {
        $this->signIn();
        $alpine = $this->category('الوبين');
        $white = $this->finish('أبيض');
        $mesh = $this->finish('ميش');
        $whiteGroup = $this->group($alpine, $white);
        $meshGroup = $this->group($alpine, $mesh);

        $parent = $this->storedWeightItem($alpine, [$whiteGroup, $meshGroup]);

        $variants = $parent->variations()->with(['categories', 'attributeValues'])->orderBy('id')->get();
        $this->assertCount(2, $variants);

        $first = $variants->first();
        $this->assertSame('ثابت مجرين W45 - أبيض', $first->translate('ar')->name);
        $this->assertSame($white->id, (int) $first->finish_id);
        $this->assertSame($whiteGroup->id, (int) $first->category_finish_id);
        $this->assertSame('weight', $first->pricing_type);
        $this->assertSame('1.2500', $first->weight_per_meter);
        $this->assertSame('6.5000', $first->length);
        $this->assertEquals(0, $first->base_price);
        $this->assertSame([$alpine->id], $first->categories->modelKeys());
        // مرآة المتجر: قيمة اللون في سمة «اللون».
        $this->assertSame([$white->attribute_value_id], $first->attributeValues->modelKeys());
        $this->assertSame($mesh->id, (int) $variants->last()->finish_id);
    }

    public function test_a_colour_from_another_category_carries_that_category_in_its_name(): void
    {
        $this->signIn();
        $alpine = $this->category('الوبين');
        $nabco = $this->category('نابكو');
        $group = $this->group($nabco, $this->finish('أبيض'));

        $parent = $this->storedWeightItem($alpine, [$group]);

        $this->assertSame('ثابت مجرين W45 - نابكو أبيض', $parent->variations()->firstOrFail()->translate('ar')->name);
    }

    public function test_weight_colour_rows_need_a_distinct_price_group_but_no_price(): void
    {
        $this->signIn();
        $alpine = $this->category('الوبين');
        $group = $this->group($alpine, $this->finish('أبيض'));

        $this->store($this->newItem(['category_id' => [$alpine->id], 'variations' => [$this->colourRow($group, ['category_finish_id' => null])]]))
            ->assertSessionHasErrors('variations.0.category_finish_id');

        $this->store($this->newItem(['category_id' => [$alpine->id], 'variations' => [$this->colourRow($group), $this->colourRow($group)]]))
            ->assertSessionHasErrors('variations.1.category_finish_id');

        $this->assertSame(0, Product::query()->count());
    }

    public function test_editing_syncs_colours_and_carries_the_parent_measures(): void
    {
        $this->signIn();
        $alpine = $this->category('الوبين');
        $whiteGroup = $this->group($alpine, $this->finish('أبيض'));
        $meshGroup = $this->group($alpine, $this->finish('ميش'));
        $parent = $this->storedWeightItem($alpine, [$whiteGroup, $meshGroup]);

        $state = $this->editState($parent);
        $this->assertSame([$whiteGroup->id, $meshGroup->id], array_column($state['variations'], 'category_finish_id'));

        // حذف «ميش» وتعطيل «أبيض» وتغيير وزن المتر.
        $white = array_merge($state['variations'][0], ['is_active' => false]);
        $this->update($parent, array_merge($state, ['weight_per_meter' => '1.5', 'variations' => [$white]]))->assertSessionHasNoErrors()->assertRedirect(route('products.index'));

        $variants = $parent->variations()->get();
        $this->assertCount(1, $variants);
        $this->assertSame('1.5000', $variants->first()->weight_per_meter);
        $this->assertSame(0, (int) $variants->first()->is_active);
        $this->assertSame(1, Product::onlyTrashed()->where('parent_id', $parent->id)->count());
    }

    public function test_a_colour_used_in_a_document_is_not_removed(): void
    {
        $this->signIn();
        $alpine = $this->category('الوبين');
        $parent = $this->storedWeightItem($alpine, [$this->group($alpine, $this->finish('أبيض'))]);
        $variant = $parent->variations()->firstOrFail();

        Schema::disableForeignKeyConstraints();
        DB::table(config('accounting.table_prefix').'stock_movements')->insert([
            'product_id' => $variant->id, 'warehouse_id' => 1, 'movement_date' => now()->toDateString(), 'type' => 'test', 'quantity' => 1,
        ]);
        Schema::enableForeignKeyConstraints();

        $this->update($parent, $this->editState($parent, ['variations' => []]))->assertSessionHasErrors('variations');

        $this->assertNotNull(Product::query()->find($variant->id));
    }

    public function test_an_accessory_variant_takes_its_colour_from_the_colour_value(): void
    {
        $this->signIn();
        $white = $this->finish('أبيض');
        $size = new Attribute(['slug' => 'size-'.Str::lower(Str::random(6))]);
        $size->translateOrNew('ar')->name = 'المقاس';
        $size->save();
        $large = new AttributeValue(['attribute_id' => $size->id]);
        $large->translateOrNew('ar')->name = 'كبير';
        $large->save();

        $this->store($this->newItem([
            'name' => 'مقبض', 'pricing_type' => 'simple', 'base_price' => '5', 'weight_per_meter' => '', 'length' => '',
            'variations' => [array_merge($this->colourRow(new CategoryFinish, [
                'name' => 'مقبض - أبيض - كبير', 'base_price' => '6', 'category_finish_id' => null,
                'attribute_values' => [$white->attribute_value_id, $large->id],
            ]))],
        ]))->assertSessionHasNoErrors();

        $variant = Product::query()->whereNotNull('parent_id')->firstOrFail();
        $this->assertSame($white->id, (int) $variant->finish_id);
        $this->assertNull($variant->category_finish_id);
        $this->assertSame('6.00', $variant->base_price);
    }

    public function test_the_colours_endpoint_lists_category_colours_with_kg_prices(): void
    {
        $this->signIn(['Product Update']);
        $alpine = $this->category('الوبين');
        $nabco = $this->category('نابكو');
        $white = $this->finish('أبيض', '#ffffff');
        $whiteGroup = $this->group($alpine, $white);
        $other = $this->group($nabco, $this->finish('ميش'));
        $this->group($nabco, $this->finish('بني'));
        $cash = PriceList::create(['name' => 'نقدي', 'is_active' => true, 'sort_order' => 1]);
        CategoryFinishPrice::create(['category_finish_id' => $whiteGroup->id, 'price_list_id' => $cash->id, 'sell_price' => '22.5', 'buy_price' => '18']);

        $this->getJson(route('products.colours', ['categories' => [$alpine->id], 'include' => [$other->id]]))
            ->assertOk()
            ->assertJsonPath('lists.0.id', $cash->id)
            ->assertJsonPath('lists.0.name', 'نقدي')
            ->assertJsonCount(2, 'colours')
            ->assertJsonPath('colours.0.id', $whiteGroup->id)
            ->assertJsonPath('colours.0.name', 'أبيض')
            ->assertJsonPath('colours.0.swatch', '#ffffff')
            ->assertJsonPath('colours.0.prices.'.$cash->id, '22.5')
            ->assertJsonPath('colours.1.id', $other->id)
            ->assertJsonPath('colours.1.name', 'نابكو ميش');

        $this->signIn(['Product View List']);
        $this->getJson(route('products.colours', ['categories' => [$alpine->id]]))->assertForbidden();
    }

    public function test_the_item_page_has_no_main_item_meta_or_free_colour(): void
    {
        $this->signIn();
        $alpine = $this->category('الوبين');
        $main = $this->product('رئيسي قديم', ['pricing_type' => 'weight']);
        $item = $this->product('مقطع', ['pricing_type' => 'weight', 'main_product_id' => $main->id]);
        $item->categories()->attach($alpine->id);

        $this->get(route('products.edit', $item->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('product.main_product_id')
                ->missing('product.meta_title')
                ->missing('product.color')
                ->missing('subProducts')
                ->missing('options.colors')
                ->has('variantStock'));

        // الحفظ من الصفحة لا يلمس ربط الصنف الرئيسي القديم (يُزال في المرحلة 4).
        $this->update($item, $this->editState($item))->assertSessionHasNoErrors();
        $this->assertSame($main->id, (int) $item->refresh()->main_product_id);
    }
}
