<?php

namespace Modules\Items\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\ItemTypeProfile;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductPrice;
use Modules\Items\Enums\CategoryStatus;
use Modules\Items\Enums\ItemDateMode;
use Modules\Items\Enums\ItemSubItemMode;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * صفحات التصنيفات وقوائم الاسعار وانواع الاصناف في لوحة Vue: القائمة والاضافة والتعديل
 * والحذف، كلها بصلاحياتها، مع قواعد النظام (رقم التصنيف، منع حذف المستخدم).
 */
class CategoryPriceListProfilePagesTest extends TestCase
{
    use RefreshDatabase;

    private const CATEGORY = ['Category View List', 'Category Create', 'Category Update', 'Category Delete', 'Category Info'];

    private const PRICE_LIST = ['Price List View List', 'Price List Create', 'Price List Update', 'Price List Delete', 'Price List Info'];

    private const PROFILE = ['Item Type Profile View List', 'Item Type Profile Create', 'Item Type Profile Update', 'Item Type Profile Delete', 'Item Type Profile Info'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');
        Storage::fake('items');

        foreach ([...self::CATEGORY, ...self::PRICE_LIST, ...self::PROFILE] as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function category(string $name, ?int $parentId = null): Category
    {
        $category = new Category([
            'slug' => 'cat-'.Str::lower(Str::random(8)),
            'status' => CategoryStatus::ACTIVE->value,
            'parent_id' => $parentId,
        ]);
        $category->save();
        $category->translateOrNew('ar')->name = $name;
        $category->save();

        return $category->refresh();
    }

    private function product(): Product
    {
        $product = new Product(['slug' => 'item-'.Str::lower(Str::random(8)), 'base_price' => 0, 'pricing_type' => 'simple']);
        $product->save();
        $product->translateOrNew('ar')->name = 'صنف '.$product->id;
        $product->save();

        return $product;
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('category.png', 400, 400);
    }

    /* ---------- التصنيفات ---------- */

    public function test_the_categories_list_is_an_inertia_table(): void
    {
        $this->signIn(self::CATEGORY);
        $root = $this->category('الكترونيات');
        $this->category('هواتف', $root->id);

        $this->get(route('categories.index', ['filter' => ['name' => 'هواتف']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Category/Index')
                ->has('table.data', 1)
                ->where('table.data.0.name', 'هواتف')
                ->where('table.data.0.parent', 'الكترونيات')
                ->has('parents', 1));
    }

    public function test_a_category_is_added_with_its_number_name_and_image(): void
    {
        $user = $this->signIn(self::CATEGORY);
        $parent = $this->category('مواد بناء');

        $this->get(route('categories.add'))
            ->assertInertia(fn (Assert $page) => $page->component('Items/Category/Add')->where('parents.0.id', $parent->id));

        $this->post(route('categories.store'), [
            'name' => 'اسمنت',
            'slug' => '',
            'parent_id' => $parent->id,
            'description' => 'وصف',
            'image' => $this->image(),
        ])->assertRedirect(route('categories.index'));

        $category = Category::query()->whereTranslation('name', 'اسمنت')->firstOrFail();

        $this->assertSame($parent->id, $category->parent_id);
        $this->assertSame($user->id, $category->created_by);
        $this->assertSame('وصف', $category->translate('ar')->description);
        // رقم الابن رقم ابيه وبعده تسلسل.
        $this->assertSame($parent->code.'001', $category->code);
        // الرابط يُصنع من الاسم اذا تُرك فارغاً.
        $this->assertNotSame('', $category->slug);
        $this->assertTrue($category->hasMedia('category'));
    }

    public function test_the_item_form_creates_a_category_in_place_without_an_image(): void
    {
        $this->signIn(self::CATEGORY);

        $response = $this->postJson(route('categories.quick-store'), ['name' => 'ألمنيوم'])->assertCreated();

        $category = Category::query()->whereTranslation('name', 'ألمنيوم')->firstOrFail();

        $response->assertExactJson(['value' => $category->id, 'label' => 'ألمنيوم']);
        $this->assertFalse($category->hasMedia('category'));

        $this->postJson(route('categories.quick-store'), ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name', 'data');
    }

    public function test_a_new_category_needs_a_name_and_a_free_slug_but_no_image(): void
    {
        $this->signIn(self::CATEGORY);
        $taken = $this->category('موجود');

        $this->post(route('categories.store'), ['name' => '', 'slug' => $taken->slug])
            ->assertSessionHasErrors(['name', 'slug'])
            ->assertSessionDoesntHaveErrors('image');

        $this->assertSame(1, Category::query()->count());
    }

    public function test_edit_updates_the_category_and_keeps_its_creator(): void
    {
        $this->signIn(self::CATEGORY);
        // بلا منشئ: التعديل لا يكتب المستخدم الحالي مكانه.
        $category = $this->category('قديم');
        $parent = $this->category('اب');

        $this->get(route('categories.edit', $category->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Category/Edit')
                ->where('category.name', 'قديم')
                ->where('category.code', $category->code)
                // التصنيف لا يظهر في قائمة الاباء لنفسه.
                ->has('parents', 1)
                ->where('parents.0.id', $parent->id));

        $this->put(route('categories.update', $category->id), [
            'name' => 'جديد',
            'slug' => 'new-slug',
            'parent_id' => $parent->id,
            'description' => '',
            'image' => $this->image(),
        ])->assertRedirect(route('categories.index'));

        $category->refresh();

        $this->assertSame('جديد', $category->translate('ar')->name);
        $this->assertSame('new-slug', $category->slug);
        $this->assertSame($parent->id, $category->parent_id);
        $this->assertNull($category->created_by);
        $this->assertTrue($category->hasMedia('category'));
        $this->assertSame(2, Category::query()->count());
    }

    public function test_a_category_cannot_be_moved_under_its_own_child(): void
    {
        $this->signIn(self::CATEGORY);
        $root = $this->category('جذر');
        $child = $this->category('فرع', $root->id);

        $this->put(route('categories.update', $root->id), [
            'name' => 'جذر',
            'slug' => $root->slug,
            'parent_id' => $child->id,
            'image' => $this->image(),
        ])->assertSessionHasErrors('parent_id');

        $this->assertNull($root->refresh()->parent_id);
    }

    public function test_a_category_with_subcategories_or_items_is_kept(): void
    {
        $this->signIn(self::CATEGORY);
        $root = $this->category('جذر');
        $this->category('فرع', $root->id);
        $withItems = $this->category('فيه اصناف');
        $withItems->products()->attach($this->product()->id);

        $this->from(route('categories.index'))->delete(route('categories.destroy', $root->id))
            ->assertSessionHas('error', __('Remove the subcategories first.'));
        $this->from(route('categories.index'))->delete(route('categories.destroy', $withItems->id))
            ->assertSessionHas('error', __('This category still has products linked to it.'));

        $this->assertNotNull($root->fresh());
        $this->assertNotNull($withItems->fresh());
    }

    public function test_a_free_category_is_deleted_softly_and_a_branch_together(): void
    {
        $this->signIn(self::CATEGORY);
        $leaf = $this->category('ورقة');

        $this->from(route('categories.index'))->delete(route('categories.destroy', $leaf->id))->assertSessionHas('success');
        // حذف ناعم كما في شجرة الاصناف: رقم التصنيف لا يُعاد استخدامه.
        $this->assertSoftDeleted($leaf);

        $root = $this->category('جذر');
        $child = $this->category('فرع', $root->id);
        $kept = $this->category('فيه اصناف');
        $kept->products()->attach($this->product()->id);

        // الاب يُحذف بعد فرعه ولو جاء قبله، والمستخدم يبقى.
        $this->from(route('categories.index'))
            ->delete(route('categories.destroy-many'), ['ids' => [$root->id, $child->id, $kept->id]])
            ->assertSessionHas('warning', __(':deleted deleted, :skipped not deleted. :reason', [
                'deleted' => 2, 'skipped' => 1, 'reason' => __('This category still has products linked to it.'),
            ]));

        $this->assertSoftDeleted($root);
        $this->assertSoftDeleted($child);
        $this->assertNotNull($kept->fresh());
    }

    public function test_category_info_returns_its_creator_and_activities(): void
    {
        $this->signIn(self::CATEGORY);
        $category = $this->category('معلومات');

        $this->getJson(route('categories.info', $category->id))
            ->assertOk()
            ->assertJsonStructure(['created_by', 'created_at', 'activities']);
    }

    public function test_every_category_action_needs_its_permission(): void
    {
        $this->signIn(['Category View List']);
        $category = $this->category('محمي');

        $this->get(route('categories.index'))->assertOk();
        $this->get(route('categories.add'))->assertForbidden();
        $this->post(route('categories.store'), ['name' => 'x'])->assertForbidden();
        $this->get(route('categories.edit', $category->id))->assertForbidden();
        $this->put(route('categories.update', $category->id), ['name' => 'x'])->assertForbidden();
        $this->delete(route('categories.destroy', $category->id))->assertForbidden();
        $this->delete(route('categories.destroy-many'), ['ids' => [$category->id]])->assertForbidden();
        // Info is basic: whoever sees the list sees the row's history (View List).
        $this->getJson(route('categories.info', $category->id))->assertOk();

        $this->signIn([]);
        $this->get(route('categories.index'))->assertForbidden();
    }

    /* ---------- قوائم الاسعار ---------- */

    public function test_the_price_lists_list_is_an_inertia_table(): void
    {
        $this->signIn(self::PRICE_LIST);
        $list = PriceList::create(['name' => 'تجزئة', 'sort_order' => 1]);
        ProductPrice::create(['price_list_id' => $list->id, 'product_id' => $this->product()->id, 'sell_price' => 1]);

        $this->get(route('price-lists.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/PriceList/Index')
                ->has('table.data', 1)
                ->where('table.data.0.name', 'تجزئة')
                ->where('table.data.0.prices_count', 1));
    }

    public function test_a_price_list_is_added_and_edited(): void
    {
        $user = $this->signIn(self::PRICE_LIST);

        $this->from(route('price-lists.index'))
            ->post(route('price-lists.store'), ['name' => 'جملة', 'sort_order' => 2, 'same_buy_sell' => true, 'is_active' => true])
            ->assertRedirect(route('price-lists.index'))
            ->assertSessionHas('success');

        $list = PriceList::query()->whereTranslation('name', 'جملة')->firstOrFail();

        $this->assertTrue($list->same_buy_sell);
        $this->assertSame(2, $list->sort_order);
        $this->assertSame($user->id, $list->created_by);

        $this->from(route('price-lists.index'))
            ->put(route('price-lists.update', $list->id), ['name' => 'جملة كبرى', 'sort_order' => 3, 'same_buy_sell' => false, 'is_active' => false])
            ->assertSessionHas('success');

        $list->refresh();

        $this->assertSame('جملة كبرى', $list->name);
        $this->assertFalse($list->is_active);
        $this->assertSame($user->id, $list->created_by);
    }

    public function test_a_price_list_needs_a_name_and_an_order(): void
    {
        $this->signIn(self::PRICE_LIST);

        $this->post(route('price-lists.store'), ['name' => '', 'sort_order' => -1])->assertSessionHasErrors(['name', 'sort_order']);
    }

    public function test_a_price_list_with_prices_is_kept_and_a_free_one_is_deleted(): void
    {
        $this->signIn(self::PRICE_LIST);
        $used = PriceList::create(['name' => 'مستخدمة']);
        ProductPrice::create(['price_list_id' => $used->id, 'product_id' => $this->product()->id, 'sell_price' => 1]);
        $free = PriceList::create(['name' => 'حرة']);

        $this->from(route('price-lists.index'))->delete(route('price-lists.destroy', $used->id))
            ->assertSessionHas('error', __('This price list has prices and cannot be deleted.'));
        $this->assertNotNull($used->fresh());

        $this->from(route('price-lists.index'))->delete(route('price-lists.destroy-many'), ['ids' => [$used->id, $free->id]])
            ->assertSessionHas('warning', __(':deleted deleted, :skipped not deleted. :reason', [
                'deleted' => 1, 'skipped' => 1, 'reason' => __('This price list has prices and cannot be deleted.'),
            ]));
        $this->assertNotNull($used->fresh());
        $this->assertNull($free->fresh());
    }

    public function test_every_price_list_action_needs_its_permission(): void
    {
        $this->signIn(['Price List View List']);
        $list = PriceList::create(['name' => 'تجزئة']);

        $this->get(route('price-lists.index'))->assertOk();
        $this->post(route('price-lists.store'), ['name' => 'x', 'sort_order' => 0])->assertForbidden();
        $this->put(route('price-lists.update', $list->id), ['name' => 'x', 'sort_order' => 0])->assertForbidden();
        $this->delete(route('price-lists.destroy', $list->id))->assertForbidden();
        $this->delete(route('price-lists.destroy-many'), ['ids' => [$list->id]])->assertForbidden();
        // Info is basic: whoever sees the list sees the row's history (View List).
        $this->getJson(route('price-lists.info', $list->id))->assertOk();

        $this->signIn([]);
        $this->get(route('price-lists.index'))->assertForbidden();
    }

    /* ---------- انواع الاصناف ---------- */

    private function profilePayload(array $overrides = []): array
    {
        return [
            'code' => 'al_custom',
            'name' => 'ألمنيوم خاص',
            'sort_order' => 3,
            'quantity_decimals' => 3,
            'price_decimals' => null,
            'notes_count' => 2,
            'date_mode' => 'lot_date',
            'bonus_mode' => 'both',
            'serial_mode' => 'serial',
            'sub_item_mode' => 'aluminium',
            'dimensions' => 2,
            'has_color' => true,
            'has_size' => false,
            'is_active' => true,
            ...$overrides,
        ];
    }

    public function test_the_item_types_list_is_an_inertia_table(): void
    {
        $this->signIn(self::PROFILE);

        // النوع الافتراضي (a_store) يأتي من الترحيل.
        $this->get(route('item-type-profiles.index', ['filter' => ['search' => 'a_store']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/ItemTypeProfile/Index')
                ->has('table.data', 1)
                ->where('table.data.0.code', 'a_store')
                ->where('table.data.0.sub_item_mode', 'none')
                ->has('modes.subItem', count(ItemSubItemMode::cases())));
    }

    public function test_an_item_type_is_added_and_edited(): void
    {
        $user = $this->signIn(self::PROFILE);

        $this->from(route('item-type-profiles.index'))
            ->post(route('item-type-profiles.store'), $this->profilePayload())
            ->assertSessionHas('success');

        $profile = ItemTypeProfile::query()->where('code', 'al_custom')->firstOrFail();

        $this->assertSame('ألمنيوم خاص', $profile->name);
        $this->assertSame($user->id, $profile->created_by);
        $this->assertSame(3, $profile->quantity_decimals);
        // بلا منازل: الحقل مخفي في سطر المستند.
        $this->assertNull($profile->price_decimals);
        $this->assertSame(ItemSubItemMode::ALUMINIUM, $profile->sub_item_mode);
        $this->assertSame(ItemDateMode::LOT_DATE, $profile->date_mode);
        $this->assertTrue($profile->has_color);

        $this->from(route('item-type-profiles.index'))
            ->put(route('item-type-profiles.update', $profile->id), $this->profilePayload(['name' => 'اسم جديد', 'sub_item_mode' => 'none']))
            ->assertSessionHas('success');

        $profile->refresh();

        $this->assertSame('اسم جديد', $profile->name);
        $this->assertSame(ItemSubItemMode::NONE, $profile->sub_item_mode);
        $this->assertSame(3, $profile->quantity_decimals);
    }

    public function test_an_item_type_rejects_values_out_of_range_and_a_repeated_code(): void
    {
        $this->signIn(self::PROFILE);

        $this->post(route('item-type-profiles.store'), $this->profilePayload([
            'code' => 'a_store',
            'quantity_decimals' => 6,
            'dimensions' => 4,
            'notes_count' => 4,
            'serial_mode' => 'unknown',
        ]))->assertSessionHasErrors(['code', 'quantity_decimals', 'dimensions', 'notes_count', 'serial_mode']);

        $this->assertSame(1, ItemTypeProfile::query()->count());
    }

    public function test_an_item_type_in_use_is_kept_and_a_free_one_is_deleted(): void
    {
        $this->signIn(self::PROFILE);
        $used = ItemTypeProfile::create(['code' => 'used', 'name' => 'مستخدم']);
        $product = $this->product();
        $product->update(['item_type_profile_id' => $used->id]);
        $free = ItemTypeProfile::create(['code' => 'free', 'name' => 'حر']);

        $this->from(route('item-type-profiles.index'))->delete(route('item-type-profiles.destroy', $used->id))
            ->assertSessionHas('error', __('This item type is used by items and cannot be deleted.'));
        $this->assertNotNull($used->fresh());
        $this->assertSame($used->id, $product->refresh()->item_type_profile_id);

        $this->from(route('item-type-profiles.index'))->delete(route('item-type-profiles.destroy-many'), ['ids' => [$used->id, $free->id]])
            ->assertSessionHas('warning', __(':deleted deleted, :skipped not deleted. :reason', [
                'deleted' => 1, 'skipped' => 1, 'reason' => __('This item type is used by items and cannot be deleted.'),
            ]));
        $this->assertNotNull($used->fresh());
        $this->assertNull($free->fresh());
    }

    public function test_every_item_type_action_needs_its_permission(): void
    {
        $this->signIn(['Item Type Profile View List']);
        $profile = ItemTypeProfile::query()->where('code', 'a_store')->firstOrFail();

        $this->get(route('item-type-profiles.index'))->assertOk();
        $this->post(route('item-type-profiles.store'), $this->profilePayload())->assertForbidden();
        $this->put(route('item-type-profiles.update', $profile->id), $this->profilePayload())->assertForbidden();
        $this->delete(route('item-type-profiles.destroy', $profile->id))->assertForbidden();
        $this->delete(route('item-type-profiles.destroy-many'), ['ids' => [$profile->id]])->assertForbidden();
        // Info is basic: whoever sees the list sees the row's history (View List).
        $this->getJson(route('item-type-profiles.info', $profile->id))->assertOk();

        $this->signIn([]);
        $this->get(route('item-type-profiles.index'))->assertForbidden();
    }
}
