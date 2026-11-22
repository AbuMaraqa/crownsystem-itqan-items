<?php

namespace Modules\Items\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Addon;
use Modules\Items\Entities\Attribute;
use Modules\Items\Entities\AttributeValue;
use Modules\Items\Entities\BranchPrice;
use Modules\Items\Entities\Brand;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\Label;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductAccessory;
use Modules\Items\Entities\Tag;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * بطاقة الصنف في لوحة Vue: قائمة الاصناف والبحث، والاضافة والتعديل بكل تبويباتها، وما يخفيه صنف
 * الوزن والمقطع، والحذف والتفعيل، واسعار الفروع، وكلها بصلاحياتها.
 */
class ProductPagesTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = [
        'Product View List', 'Product Create', 'Product Update', 'Product Delete', 'Product Info', 'Product View',
        'Product Branch Pricings', 'Product Units And Prices', 'Stock Limit Update',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');
        Storage::fake('items');
        // حفظ الصنف يبث حدثاً، ولا خادم بث في الاختبارات.
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

    private function product(string $name = 'صنف', array $attributes = []): Product
    {
        $product = new Product(array_merge(['slug' => 'item-'.Str::lower(Str::random(8)), 'base_price' => 0, 'pricing_type' => 'simple'], $attributes));
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    /** لون ومرآته في سمة «اللون». */
    private function finish(string $name, ?string $hex = null): Finish
    {
        $value = new AttributeValue(['attribute_id' => Finish::colourAttributeId(), 'color_code' => $hex]);
        $value->translateOrNew('ar')->name = $name;
        $value->save();

        return Finish::create(['name' => $name, 'is_active' => true, 'attribute_value_id' => $value->id]);
    }

    private function category(string $name = 'فئة'): Category
    {
        $category = Category::create(['slug' => 'cat-'.Str::lower(Str::random(8)), 'status' => 1]);
        $category->translateOrNew('ar')->name = $name;
        $category->save();

        return $category;
    }

    /**
     * ما ترسله صفحة الاضافة لصنف جديد (انظر emptyProduct في productForm.ts).
     *
     * @return array<string, mixed>
     */
    private function newItem(array $overrides = []): array
    {
        return array_merge([
            'name' => 'باب المنيوم', 'slug' => '', 'short_description' => '', 'description' => '', 'sku' => '', 'barcode' => '',
            'base_price' => '10', 'sale_price' => '', 'cost_price' => '', 'wholesale_price' => '',
            'discount_type' => null, 'discount_value' => '', 'discount_start_date' => '', 'discount_end_date' => '',
            'max_limit' => '', 'min_limit_1' => '', 'min_limit_2' => '', 'category_id' => [],
            'removed_image' => [], 'removed_gallery' => [],
            'weight_per_meter' => '', 'length' => '', 'pricing_type' => 'simple', 'item_type_profile_id' => null,
            'bundle_count' => '', 'profile_thickness' => null, 'is_open_profile' => null, 'area' => '',
            'item_type' => 'goods', 'service_kind' => 'general', 'service_cost_source' => 'internal',
            'revenue_account_id' => null, 'cost_account_id' => null, 'site_area' => '',
            'brand_id' => null, 'unit_id' => null, 'tags' => [], 'labels' => [], 'manufacturers' => [], 'branches' => [],
            'addons' => [], 'accessories' => [], 'cutting_recipes' => [], 'variations' => [],
        ], $overrides);
    }

    /**
     * حالة صفحة التعديل كما تعيدها الصفحة بلا تغيير، بعد دمج التعديلات.
     *
     * @return array<string, mixed>
     */
    private function editState(Product $product, array $overrides = []): array
    {
        $state = $this->get(route('products.edit', $product->id))->assertOk()->viewData('page')['props']['product'];
        unset($state['id'], $state['images'], $state['gallery_images'], $state['attributes']);
        $state['variations'] = array_map(fn (array $row) => array_diff_key($row, ['images' => 1, 'gallery_images' => 1]), $state['variations']);

        return array_merge($state, ['removed_image' => [], 'removed_gallery' => []], $overrides);
    }

    private function store(array $data, array $files = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('products.store'), ['payload' => json_encode($data), ...$files]);
    }

    private function update(Product $product, array $data, array $files = []): \Illuminate\Testing\TestResponse
    {
        return $this->put(route('products.update', $product->id), ['payload' => json_encode($data), ...$files]);
    }

    /* ---------- القائمة ---------- */

    public function test_the_list_needs_its_permission_and_shows_items_with_their_colours(): void
    {
        $this->signIn([]);
        $this->get(route('products.index'))->assertForbidden();

        $this->signIn(['Product View List']);
        $white = $this->finish('أبيض', '#ffffff');
        $mesh = $this->finish('ميش');
        $profile = $this->product('ثابت مجرين W45', ['sku' => '00090001', 'pricing_type' => 'weight']);
        $this->product('ثابت مجرين W45 - أبيض', ['parent_id' => $profile->id, 'finish_id' => $white->id, 'pricing_type' => 'weight']);
        $this->product('ثابت مجرين W45 - ميش', ['parent_id' => $profile->id, 'finish_id' => $mesh->id, 'pricing_type' => 'weight', 'is_active' => 0]);
        // صنف قديم كان تحت صنف رئيسي: صف عادي في تصنيفه.
        $old = $this->product('كباس زجاج ثابت', ['sku' => 'BLT5227', 'pricing_type' => 'weight', 'main_product_id' => $profile->id]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Product/Index')
                ->has('table.data', 2)
                ->where('table.data.0.id', $old->id)
                ->where('table.data.1.id', $profile->id)
                ->where('table.data.1.colours.count', 2)
                ->where('table.data.1.colours.rows.0.name', 'أبيض')
                ->where('table.data.1.colours.rows.0.swatch', '#ffffff')
                ->where('table.data.1.colours.rows.1.active', false)
                ->where('table.data.0.colours', null)
                ->missing('table.data.0.sub_items')
                ->has('categories')
                ->has('finishes'));

        // البحث يجد الصنف بنفسه فقط.
        $this->get(route('products.index', ['filter' => ['search' => 'BLT5227']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.id', $old->id));

        // تصفية اللون: الاصناف التي لها هذا اللون.
        $this->get(route('products.index', ['filter' => ['finish_id' => $mesh->id]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.id', $profile->id));
    }

    public function test_the_list_filters_by_category_pricing_type_and_activity(): void
    {
        $this->signIn(['Product View List']);
        $category = $this->category();
        $main = $this->product('المنيوم', ['pricing_type' => 'weight']);
        $main->categories()->attach($category->id);
        $this->product('مقطع', ['pricing_type' => 'weight']);
        $door = $this->product('باب');
        $door->forceFill(['is_active' => 0])->save();

        $this->get(route('products.index', ['filter' => ['category_id' => $category->id]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.id', $main->id));

        $this->get(route('products.index', ['filter' => ['pricing_type' => 'simple']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.id', $door->id));
        $this->get(route('products.index', ['filter' => ['pricing_type' => 'weight']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 2));

        $this->get(route('products.index', ['filter' => ['is_active' => 0]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.id', $door->id)->where('table.data.0.is_active', false));
    }

    public function test_activating_an_item_needs_the_update_permission(): void
    {
        $product = $this->product();

        $this->signIn(['Product View List']);
        $this->put(route('products.active', $product->id), ['active' => false])->assertForbidden();

        $this->signIn(['Product Update']);
        $this->put(route('products.active', $product->id), ['active' => false])->assertSessionHas('success', __('Product deactivated successfully'));
        $this->assertSame(0, (int) $product->refresh()->is_active);
    }

    public function test_delete_follows_the_deletion_blocker(): void
    {
        $this->signIn(['Product View List', 'Product Delete']);
        $main = $this->product('رئيسي', ['pricing_type' => 'weight']);
        $piece = $this->product('مقطع', ['pricing_type' => 'weight', 'main_product_id' => $main->id]);
        $free = $this->product('حر');

        $this->delete(route('products.destroy', $main->id))->assertSessionHas('error', __('Remove the sub-items of this main item first.'));
        $this->assertNotNull(Product::query()->find($main->id));

        // الحذف المفرد نهائي.
        $this->delete(route('products.destroy', $free->id))->assertSessionHas('success');
        $this->assertNull(Product::withTrashed()->find($free->id));

        // حذف التحديد يترك الممنوع ويخبر به.
        $this->delete(route('products.destroy-many'), ['ids' => [$main->id, $piece->id]])
            ->assertSessionHas('error', __('Some items were not deleted because they have sub-items or are used in documents.'));
        $this->assertNotNull(Product::query()->find($main->id));
        $this->assertNull(Product::query()->find($piece->id));
    }

    public function test_the_barcode_dialog_lists_the_item_and_its_variations(): void
    {
        $this->signIn(['Product View List']);
        $product = $this->product('قميص', ['barcode' => '123456']);
        $this->product('قميص - احمر', ['parent_id' => $product->id]);

        $this->getJson(route('products.barcodes', $product->id))
            ->assertOk()
            ->assertJsonCount(2, 'rows')
            ->assertJsonPath('rows.0.code', '123456');
    }

    /* ---------- الاضافة ---------- */

    public function test_the_add_page_sends_the_form_lists(): void
    {
        $this->signIn(['Product Create']);
        $this->category('مواد');

        $this->get(route('products.add'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Product/Add')
                ->has('options.categories', 1)
                ->has('options.itemTypes', 2)
                ->where('options.canEditLimits', false)
                ->has('options.attributes')
                ->has('options.addons'));
    }

    public function test_a_simple_item_is_added_with_its_relations(): void
    {
        $user = $this->signIn();
        $category = $this->category();
        $lock = $this->product('قفل');
        $glass = $this->product('زجاج', ['length' => 1]);
        $addon = new Addon(['created_by' => $user->id]);
        $addon->translateOrNew('ar')->name = 'اضافة';
        $addon->save();

        $this->store($this->newItem([
            'name' => 'شباك',
            'barcode' => 'W-1',
            'sale_price' => '15',
            'category_id' => [$category->id],
            'tags' => ['وسم جديد'],
            'labels' => ['شارة'],
            'length' => '6',
            'max_limit' => '30', 'min_limit_1' => '20', 'min_limit_2' => '10',
            'accessories' => [['accessory_product_id' => $lock->id, 'quantity' => '2']],
            'cutting_recipes' => [['output_product_id' => $glass->id, 'pieces_per_unit' => '6']],
            'addons' => [['addon_id' => $addon->id, 'is_required' => true, 'min_selection' => 1, 'max_selection' => null, 'max_quantity_per_option' => 1, 'allowed_options' => []]],
        ]), ['image' => UploadedFile::fake()->image('item.png', 400, 400)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('products.index'));

        $product = Product::query()->whereTranslation('name', 'شباك')->firstOrFail();

        $this->assertSame('W-1', $product->barcode);
        $this->assertSame('10.00', $product->base_price);
        $this->assertSame($user->id, (int) $product->created_by);
        // الرقم والرابط يُصنعان عند الخلو.
        $this->assertStringStartsWith('SKU-', $product->sku);
        $this->assertNotSame('', $product->slug);
        $this->assertSame([$category->id], $product->categories()->pluck((new Category)->getTable().'.id')->all());
        $this->assertSame(1, $product->tags()->count());
        $this->assertSame(1, $product->labels()->count());
        $this->assertSame('30.00', $product->max_limit);
        $this->assertSame([$lock->id], $product->accessories()->pluck('accessory_product_id')->all());
        $this->assertSame(1, $product->cuttingRecipesAsSource()->count());
        $this->assertSame(1, $product->addons()->count());
        $this->assertTrue($product->hasMedia('product'));
    }

    public function test_a_simple_item_needs_a_base_price_and_valid_limits(): void
    {
        $this->signIn();

        $this->store($this->newItem(['base_price' => '', 'max_limit' => '10', 'min_limit_1' => '20', 'min_limit_2' => '5']))
            ->assertSessionHasErrors(['base_price', 'min_limit_1']);

        $this->store($this->newItem(['name' => '']))->assertSessionHasErrors(['name' => __('Name is required')]);

        $this->assertSame(0, Product::query()->count());
    }

    public function test_limits_are_kept_away_from_a_user_without_the_limit_permission(): void
    {
        $this->signIn(['Product Create']);

        $this->store($this->newItem(['name' => 'بلا حدود', 'max_limit' => '30', 'min_limit_1' => '1', 'min_limit_2' => '100']))->assertSessionHasNoErrors();

        $this->assertNull(Product::query()->whereTranslation('name', 'بلا حدود')->firstOrFail()->max_limit);
    }

    public function test_a_repeated_name_gets_a_unique_slug(): void
    {
        $this->signIn();

        foreach ([1, 2] as $ignored) {
            $this->store($this->newItem(['name' => 'Kabbas', 'pricing_type' => 'weight', 'cost_price' => '5']))
                ->assertSessionHasNoErrors();
        }

        $items = Product::query()->whereTranslation('name', 'Kabbas')->get();
        $this->assertCount(2, $items);
        $this->assertCount(2, $items->pluck('slug')->unique());
        // صنف الوزن بلا سعر ولا تكلفة في بطاقته.
        $this->assertNull($items->first()->cost_price);
    }

    public function test_a_service_drops_stock_fields(): void
    {
        $this->signIn();

        $this->store($this->newItem([
            'name' => 'نقل', 'item_type' => 'service', 'service_kind' => 'transport',
            'max_limit' => '3', 'min_limit_1' => '2', 'min_limit_2' => '1',
        ]))->assertSessionHasNoErrors();

        $service = Product::query()->whereTranslation('name', 'نقل')->firstOrFail();
        $this->assertSame('service', $service->item_type);
        $this->assertSame('transport', $service->service_kind);
        $this->assertNull($service->max_limit);
        $this->assertSame(0, (int) $service->manage_stock);
    }

    public function test_variations_are_created_from_the_rows_and_synced_on_edit(): void
    {
        $user = $this->signIn();
        $attribute = new Attribute(['slug' => 'color-'.Str::lower(Str::random(6))]);
        $attribute->translateOrNew('ar')->name = 'اللون';
        $attribute->save();
        $values = collect(['احمر', 'ازرق'])->map(function (string $name) use ($attribute, $user) {
            $value = new AttributeValue(['attribute_id' => $attribute->id, 'created_by' => $user->id]);
            $value->translateOrNew('ar')->name = $name;
            $value->save();

            return $value;
        });

        $row = fn (string $name, int $valueId) => [
            'id' => null, 'name' => $name, 'slug' => '', 'sku' => '', 'barcode' => '', 'base_price' => '12', 'attribute_values' => [$valueId],
            'category_id' => [], 'max_limit' => '', 'min_limit_1' => '', 'min_limit_2' => '',
        ];

        $this->store($this->newItem([
            'name' => 'Shirt',
            'category_id' => [$this->category()->id],
            'variations' => [$row('Shirt - احمر', $values[0]->id), $row('Shirt - ازرق', $values[1]->id)],
        ]))->assertSessionHasNoErrors();

        $product = Product::query()->whereTranslation('name', 'Shirt')->firstOrFail();
        $this->assertSame(2, $product->variations()->count());

        // صفحة التعديل تعيد المتغيرات والقيم المختارة؛ حذف صف يحذف متغيره.
        $state = $this->editState($product);
        $this->assertCount(2, $state['variations']);
        $state['variations'] = [array_merge($state['variations'][0], ['base_price' => '14'])];

        $this->update($product, $state)->assertSessionHasNoErrors();

        $this->assertSame(1, $product->variations()->count());
        $this->assertSame('14.00', $product->variations()->first()->base_price);
    }

    /* ---------- التعديل ---------- */

    public function test_the_edit_page_opens_saved_values(): void
    {
        $this->signIn(['Product Update']);
        $product = $this->product('مقطع', ['pricing_type' => 'weight', 'profile_thickness' => 'thick', 'is_open_profile' => true, 'bundle_count' => 4]);

        $this->get(route('products.edit', $product->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Product/Edit')
                ->where('product.profile_thickness', 'thick')
                ->where('product.is_open_profile', 1)
                ->where('product.bundle_count', 4)
                ->where('stock', null)
                ->where('variantStock', []));
    }

    public function test_the_edit_page_saves_and_keeps_the_category_required(): void
    {
        $this->signIn();
        $product = $this->product();
        $category = $this->category();

        $this->update($product, $this->editState($product))->assertSessionHasErrors('category_id');

        $this->update($product, $this->editState($product, ['category_id' => [$category->id], 'name' => 'اسم جديد', 'sku' => '']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('products.index'));

        $product->refresh();
        $this->assertSame('اسم جديد', $product->translate('ar')->name);
        // الرقم الفارغ يبقي رقم الصنف الحالي.
        $this->assertNotEmpty($product->sku);
    }

    public function test_an_old_main_item_with_sub_items_stays_priced_by_weight(): void
    {
        $this->signIn();
        $main = $this->product('المنيوم سبير', ['pricing_type' => 'weight']);
        $this->product('كباس', ['pricing_type' => 'weight', 'main_product_id' => $main->id]);
        $main->categories()->attach($this->category()->id);

        // ربط الصنف الرئيسي القديم يبقى حتى تُزيله المرحلة 4، فلا يُحوَّل اصله الى «بالكمية».
        $this->update($main, $this->editState($main, ['pricing_type' => 'simple', 'base_price' => '1']))->assertSessionHasErrors('pricing_type');
    }

    public function test_accessories_are_saved_in_order_and_never_the_item_itself(): void
    {
        $this->signIn();
        $window = $this->product('شباك');
        $window->categories()->attach($this->category()->id);
        $rubber = $this->product('مطاط');
        $handle = $this->product('مقبض');
        $lock = $this->product('قفل');
        ProductAccessory::create(['product_id' => $window->id, 'accessory_product_id' => $lock->id, 'quantity' => 1]);

        // البطاقة تُفتح على ملحقاتها المحفوظة، والحفظ يستبدلها بما في النموذج وبترتيبه.
        $this->assertSame([$lock->id], array_column($this->editState($window)['accessories'], 'accessory_product_id'));

        $this->update($window, $this->editState($window, ['accessories' => [
            ['accessory_product_id' => $rubber->id, 'quantity' => '6.5'],
            ['accessory_product_id' => $handle->id, 'quantity' => '2'],
        ]]))->assertSessionHasNoErrors();

        $saved = $window->accessories()->orderBy('sort_order')->get();
        $this->assertSame([$rubber->id, $handle->id], $saved->pluck('accessory_product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['6.5000', '2.0000'], $saved->pluck('quantity')->all());

        $this->update($window, $this->editState($window, ['accessories' => [['accessory_product_id' => $window->id, 'quantity' => '1']]]))
            ->assertSessionHasErrors('accessories.0.accessory_product_id');
    }

    public function test_images_are_added_and_removed(): void
    {
        $this->signIn();
        $product = $this->product();
        $product->categories()->attach($this->category()->id);

        $this->update($product, $this->editState($product), [
            'image' => UploadedFile::fake()->image('a.png', 400, 400),
            // واحد فقط: Product::addGalleryImage يتوقف عند اول خطأ تحجيم (بلا Imagick هنا).
            'gallery' => [UploadedFile::fake()->image('b.png', 400, 400)],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($product->refresh()->hasMedia('product'));
        $this->assertCount(1, $product->getMedia('product_gallery'));

        $removed = $product->getMedia('product_gallery')->first()->id;
        $this->update($product, $this->editState($product, ['removed_gallery' => [$removed], 'removed_image' => [$product->getFirstMedia('product')->id]]))->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertCount(0, $product->getMedia('product_gallery'));
        $this->assertFalse($product->hasMedia('product'));
    }

    public function test_edit_needs_its_permission(): void
    {
        $product = $this->product();

        $this->signIn(['Product View List']);
        $this->get(route('products.edit', $product->id))->assertForbidden();
        $this->put(route('products.update', $product->id), ['payload' => '{}'])->assertForbidden();
        $this->get(route('products.add'))->assertForbidden();
    }

    /* ---------- اسعار الفروع ---------- */

    private function branch(string $name): int
    {
        Schema::disableForeignKeyConstraints();
        $id = DB::table(config('branch.table_prefix').'branches')->insertGetId([
            'city_id' => 1, 'country_id' => 1, 'status' => 1, 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();
        DB::table(config('branch.table_prefix').'branch_translations')->insert(['branch_id' => $id, 'locale' => 'ar', 'name' => $name]);

        return $id;
    }

    public function test_branch_prices_are_shown_and_saved(): void
    {
        $this->signIn(['Product Branch Pricings']);
        $hebron = $this->branch('فرع الخليل');
        $product = $this->product('قميص');
        $variation = $this->product('قميص - احمر', ['parent_id' => $product->id]);
        $other = $this->product('آخر');
        BranchPrice::create(['product_id' => $product->id, 'branch_id' => $hebron, 'price' => 5]);

        $this->get(route('products.branch-pricings', $product->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Product/BranchPricings')
                ->has('rows', 2)
                ->where('branches.0.name', 'فرع الخليل'));

        $this->put(route('products.branch-pricings.update', $product->id), ['prices' => [
            $product->id => [$hebron => ''],
            $variation->id => [$hebron => '7.5'],
            $other->id => [$hebron => '99'],
        ]])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertFalse(BranchPrice::query()->where('product_id', $product->id)->exists());
        $this->assertEquals(7.5, BranchPrice::query()->where('product_id', $variation->id)->value('price'));
        // صنف آخر لا يُكتب من هذه الصفحة.
        $this->assertFalse(BranchPrice::query()->where('product_id', $other->id)->exists());

        $this->put(route('products.branch-pricings.update', $product->id), ['prices' => [$product->id => [$hebron => '-1']]])
            ->assertSessionHasErrors("prices.{$product->id}.{$hebron}");
    }
}
