<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Addon;
use Modules\Items\Entities\Brand;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\ItemTypeProfile;
use Modules\Items\Entities\Label;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\Tag;
use Modules\Items\Enums\ProfileThickness;
use Modules\Items\Services\ProductBarcodeService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * نوع الصنف وحقول المقطع في صفحتي اضافة الصنف وتعديله (لوحة Vue)، وما يخفيه صنف الوزن والمقطع:
 * ما لا يظهر في البطاقة لا يُكتب، فيبقى المحفوظ عند التعديل.
 */
class ProductFormItemModelFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        // حفظ الصنف يبث حدثاً، ولا خادم بث في الاختبارات.
        config(['broadcasting.default' => 'null']);
        app()->setLocale('ar');

        $user = User::factory()->create();

        foreach (['Product Create', 'Product Update'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $this->actingAs($user);
    }

    private function aluminiumProfile(): ItemTypeProfile
    {
        return ItemTypeProfile::create(['code' => 'al_'.Str::lower(Str::random(6)), 'name' => 'ألمنيوم', 'sub_item_mode' => 'aluminium']);
    }

    private function product(array $attributes = []): Product
    {
        $product = new Product(array_merge(['slug' => 'item-'.Str::lower(Str::random(8)), 'base_price' => 0, 'pricing_type' => 'simple'], $attributes));
        $product->save();
        $product->translateOrNew(app()->getLocale())->name = 'صنف';
        $product->save();

        return $product;
    }

    /**
     * التصنيف مطلوب عند الحفظ في صفحة التعديل.
     */
    private function categorizedProduct(array $attributes = []): Product
    {
        $product = $this->product($attributes);

        $category = Category::create(['slug' => 'cat-'.Str::lower(Str::random(8)), 'code' => 'C'.random_int(1000, 9999), 'status' => 1]);
        $category->translateOrNew(app()->getLocale())->name = 'فئة';
        $category->save();
        $product->categories()->attach($category->id);

        return $product;
    }

    /**
     * ما ترسله صفحة التعديل: حالتها كما فُتحت، مع التغييرات.
     */
    private function save(Product $product, array $changes = []): TestResponse
    {
        $state = $this->get(route('products.edit', $product->id))->assertOk()->viewData('page')['props']['product'];
        unset($state['id'], $state['images'], $state['gallery_images'], $state['attributes']);
        $state['variations'] = array_map(fn (array $row) => array_diff_key($row, ['images' => 1, 'gallery_images' => 1]), $state['variations']);

        return $this->put(route('products.update', $product->id), ['payload' => json_encode([...$state, 'removed_image' => [], 'removed_gallery' => [], ...$changes])]);
    }

    /**
     * ما ترسله صفحة الاضافة لصنف جديد.
     */
    private function add(array $fields): TestResponse
    {
        return $this->post(route('products.store'), ['payload' => json_encode([
            'name' => 'صنف', 'slug' => '', 'pricing_type' => 'simple', 'item_type' => 'goods', 'base_price' => '1',
            'category_id' => [], 'tags' => [], 'labels' => [], 'manufacturers' => [], 'branches' => [],
            'addons' => [], 'accessories' => [], 'cutting_recipes' => [], 'variations' => [],
            ...$fields,
        ])]);
    }

    public function test_the_edit_page_saves_the_profile_and_aluminium_fields(): void
    {
        $profile = $this->aluminiumProfile();
        $product = $this->categorizedProduct();

        // نوع الالمنيوم يُسعَّر بالوزن، والصفحة تعرف ذلك من قائمة الانواع.
        $this->get(route('products.edit', $product->id))
            ->assertInertia(fn (Assert $page) => $page->where('options.itemTypeProfiles', fn ($profiles) => collect($profiles)->contains(
                fn ($option) => $option['value'] === $profile->id && $option['aluminium'] === true
            )));

        $this->save($product, [
            'item_type_profile_id' => $profile->id,
            'bundle_count' => 30,
            'profile_thickness' => 'thin',
            'is_open_profile' => 0,
            'area' => '2.5',
        ])->assertSessionHasNoErrors();

        $product->refresh();

        $this->assertSame($profile->id, $product->item_type_profile_id);
        $this->assertSame('weight', $product->pricing_type);
        $this->assertSame(30, (int) $product->bundle_count);
        $this->assertSame(ProfileThickness::THIN, $product->profile_thickness);
        $this->assertFalse($product->is_open_profile);
        $this->assertSame('2.5000', $product->area);
    }

    public function test_the_edit_page_opens_saved_values(): void
    {
        $product = $this->product(['pricing_type' => 'weight', 'profile_thickness' => 'thick', 'is_open_profile' => true, 'bundle_count' => 4]);

        $this->get(route('products.edit', $product->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.profile_thickness', 'thick')
                ->where('product.is_open_profile', 1)
                ->where('product.bundle_count', 4));
    }

    public function test_the_edit_page_accepts_a_barcode_used_by_another_item(): void
    {
        app(ProductBarcodeService::class)->save($this->product(), ['barcode' => 'SHARED-EDIT']);
        $product = $this->categorizedProduct();

        $this->save($product, ['barcode' => 'SHARED-EDIT'])->assertSessionHasNoErrors();

        $this->assertSame('SHARED-EDIT', $product->refresh()->barcode);
    }

    public function test_a_weight_main_item_is_saved_from_the_add_page_without_a_price(): void
    {
        $category = $this->categorizedProduct()->categories()->first();

        $this->add([
            'name' => 'المنيوم بدون دهان',
            'slug' => 'alu-no-paint',
            'category_id' => [$category->id],
            'pricing_type' => 'weight',
            'base_price' => '', 'sale_price' => '12', 'cost_price' => '7',
        ])->assertSessionHasNoErrors();

        $product = Product::query()->where('slug', 'alu-no-paint')->firstOrFail();
        $this->assertSame('weight', $product->pricing_type);
        $this->assertEquals(0, $product->base_price);
        $this->assertNull($product->sale_price);
        // لا سعر تكلفة يُكتب في البطاقة: التكلفة من المشتريات (المتوسط المرجّح).
        $this->assertNull($product->cost_price);
    }

    public function test_a_simple_item_still_needs_a_base_price(): void
    {
        $this->add(['name' => 'إكسسوار', 'slug' => 'accessory-item', 'base_price' => ''])->assertSessionHasErrors(['base_price']);

        $this->assertFalse(Product::query()->where('slug', 'accessory-item')->exists());
    }

    public function test_the_add_page_drops_commerce_fields_of_a_weight_item(): void
    {
        $brand = Brand::create(['slug' => 'brand-'.Str::lower(Str::random(6)), 'created_by' => auth()->id()]);

        $this->add(['name' => 'وزن', 'slug' => 'weight-item', 'pricing_type' => 'weight', 'tags' => ['وسم'], 'labels' => ['شارة'], 'brand_id' => $brand->id])
            ->assertSessionHasNoErrors();

        $product = Product::query()->where('slug', 'weight-item')->firstOrFail();
        $this->assertSame(0, $product->tags()->count());
        $this->assertSame(0, $product->labels()->count());
        $this->assertNull($product->brand_id);
    }

    public function test_editing_a_weight_item_keeps_hidden_values_already_saved(): void
    {
        $brand = Brand::create(['slug' => 'brand-'.Str::lower(Str::random(6)), 'created_by' => auth()->id()]);
        $tag = new Tag(['slug' => 'tag-'.Str::lower(Str::random(6)), 'created_by' => auth()->id()]);
        $tag->translateOrNew(app()->getLocale())->name = 'وسم';
        $tag->save();
        $label = new Label(['slug' => 'label-'.Str::lower(Str::random(6)), 'created_by' => auth()->id()]);
        $label->translateOrNew(app()->getLocale())->name = 'شارة';
        $label->save();
        $addon = new Addon(['created_by' => auth()->id()]);
        $addon->translateOrNew(app()->getLocale())->name = 'اضافة';
        $addon->save();

        $product = $this->categorizedProduct();
        $product->forceFill(['pricing_type' => 'weight', 'brand_id' => $brand->id])->save();
        $product->tags()->sync([$tag->id]);
        $product->labels()->sync([$label->id]);
        $product->addons()->attach($addon->id, ['created_by' => auth()->id()]);

        // الصفحة لا ترسل هذه الحقول لصنف الوزن؛ ولو وصلت فارغة لا تُكتب. (ألوانه متغيّرات تُحفظ: ProductColoursTest.)
        $this->save($product, ['tags' => [], 'labels' => [], 'brand_id' => null, 'addons' => []])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame($brand->id, (int) $product->brand_id);
        $this->assertSame([$tag->id], $product->tags()->pluck($tag->getTable().'.id')->all());
        $this->assertSame([$label->id], $product->labels()->pluck($label->getTable().'.id')->all());
        $this->assertSame([$addon->id], $product->addons()->pluck($addon->getTable().'.id')->all());
    }

    public function test_a_simple_item_still_saves_its_commerce_fields_from_the_edit_page(): void
    {
        $product = $this->categorizedProduct();

        $this->save($product, ['tags' => ['وسم جديد']])->assertSessionHasNoErrors();

        $this->assertSame(1, $product->tags()->count());
    }

    public function test_a_piece_keeps_its_cost_and_editing_a_weight_item_keeps_saved_prices(): void
    {
        $main = $this->categorizedProduct();
        $main->forceFill(['pricing_type' => 'weight', 'base_price' => 15, 'sale_price' => 17, 'cost_price' => 9])->save();
        $piece = $this->categorizedProduct();
        $piece->forceFill(['pricing_type' => 'weight', 'main_product_id' => $main->id, 'cost_price' => 3])->save();

        $this->save($piece, ['cost_price' => '50', 'base_price' => '50'])->assertSessionHasNoErrors();
        $this->assertEquals(3, $piece->refresh()->cost_price);
        $this->assertEquals(0, $piece->base_price);

        $this->save($main, ['base_price' => '', 'sale_price' => ''])->assertSessionHasNoErrors();

        $main->refresh();
        $this->assertEquals(15, $main->base_price);
        $this->assertEquals(17, $main->sale_price);
        $this->assertEquals(9, $main->cost_price);
    }
}
