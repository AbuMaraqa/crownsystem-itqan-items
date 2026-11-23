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
use Modules\Items\Entities\Brand;
use Modules\Items\Entities\Label;
use Modules\Items\Entities\Manufacturer;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\Tag;
use Modules\Items\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * صفحات البيانات المساعدة للاصناف في لوحة Vue (العلامات التجارية، والوسوم، والملصقات، والشركات
 * المصنعة، والوحدات): القائمة والاضافة والتعديل والحذف والمعلومات، كلها بصلاحياتها.
 */
class CatalogLookupPagesTest extends TestCase
{
    use RefreshDatabase;

    private const ENTITIES = ['Brand', 'Tag', 'Label', 'Manufacturer', 'Unit'];

    private const ACTIONS = ['View List', 'View', 'Info', 'Create', 'Update', 'Delete'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');
        Storage::fake('items');

        foreach ($this->permissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    /**
     * @return list<string>
     */
    private function permissions(?array $actions = null): array
    {
        $names = [];

        foreach (self::ENTITIES as $entity) {
            foreach ($actions ?? self::ACTIONS as $action) {
                $names[] = "{$entity} {$action}";
            }
        }

        return $names;
    }

    private function signIn(?array $permissions = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions ?? $this->permissions());
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  class-string<Brand|Tag|Label|Manufacturer|Unit>  $class
     */
    private function record(string $class, string $name, array $columns = []): Brand|Tag|Label|Manufacturer|Unit
    {
        $record = new $class(['slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'created_by' => auth()->id(), ...$columns]);
        $record->translateOrNew('ar')->name = $name;
        $record->save();

        return $record;
    }

    private function product(array $attributes): Product
    {
        $sku = 'SKU-'.Str::upper(Str::random(8));
        $product = new Product([
            'slug' => Str::slug($sku),
            'sku' => $sku,
            'base_price' => 0,
            'pricing_type' => 'simple',
            'is_active' => 1,
            ...$attributes,
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = 'صنف';
        $product->save();

        return $product;
    }

    public function test_each_list_is_an_inertia_table(): void
    {
        $this->signIn();
        $this->record(Brand::class, 'كراون');
        $this->record(Tag::class, 'جديد');
        $this->record(Label::class, 'عرض', ['icon' => 'star']);
        $this->record(Manufacturer::class, 'المصنع الاول');
        $this->record(Unit::class, 'متر');

        $pages = [
            'brands' => ['Items/Brand/Index', 'كراون'],
            'tags' => ['Items/Tag/Index', 'جديد'],
            'labels' => ['Items/Label/Index', 'عرض'],
            'manufacturers' => ['Items/Manufacturer/Index', 'المصنع الاول'],
            'units' => ['Items/Unit/Index', 'متر'],
        ];

        foreach ($pages as $routes => [$component, $name]) {
            $this->get(route("{$routes}.index"))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->has('table.data', 1)
                    ->where('table.data.0.name', $name)
                    ->where('table.data.0.form.name', $name)
                    ->where('table.meta.total', 1));
        }

        $this->get(route('labels.index'))->assertInertia(fn (Assert $page) => $page->where('table.data.0.icon', 'star'));
    }

    public function test_the_name_filter_and_search_narrow_the_list(): void
    {
        $this->signIn();
        $this->record(Unit::class, 'متر');
        $this->record(Unit::class, 'كيلوغرام');

        $this->get(route('units.index', ['filter' => ['name' => 'كيلو']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.name', 'كيلوغرام'));

        $this->get(route('units.index', ['filter' => ['search' => 'متر']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.name', 'متر'));
    }

    public function test_a_brand_is_added_with_its_arabic_name_and_image(): void
    {
        $this->signIn();

        $this->post(route('brands.store'), [
            'name' => 'كراون',
            'slug' => 'crown',
            'description' => 'وصف',
            'image' => UploadedFile::fake()->image('crown.png', 400, 400),
        ])->assertSessionHas('success');

        $brand = Brand::query()->where('slug', 'crown')->firstOrFail();
        $this->assertSame('كراون', $brand->translate('ar')->name);
        $this->assertNotSame('', $brand->getFirstMediaUrl('brand'));
    }

    public function test_a_brand_needs_an_image_when_added_but_not_when_edited(): void
    {
        $this->signIn();

        $this->post(route('brands.store'), ['name' => 'كراون', 'slug' => 'crown'])->assertSessionHasErrors('image');

        $brand = $this->record(Brand::class, 'كراون');
        $this->put(route('brands.update', $brand->id), ['name' => 'كراون الجديدة', 'slug' => 'crown-new'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $brand->refresh();
        $this->assertSame('crown-new', $brand->slug);
        $this->assertSame('كراون الجديدة', $brand->translate('ar')->name);
        $this->assertSame(1, Brand::query()->count());
    }

    public function test_a_manufacturer_is_added_without_an_image_and_its_image_can_be_replaced(): void
    {
        $this->signIn();

        $this->post(route('manufacturers.store'), ['name' => 'المصنع الاول', 'slug' => 'factory-one'])->assertSessionHas('success');

        $manufacturer = Manufacturer::query()->where('slug', 'factory-one')->firstOrFail();
        $this->assertSame('', $manufacturer->getFirstMediaUrl('manufacturer'));

        $this->put(route('manufacturers.update', $manufacturer->id), [
            'name' => 'المصنع الاول',
            'slug' => 'factory-one',
            'image' => UploadedFile::fake()->image('factory.png'),
        ])->assertSessionHas('success');

        $this->assertNotSame('', $manufacturer->refresh()->getFirstMediaUrl('manufacturer'));
    }

    public function test_tags_and_labels_keep_their_colors(): void
    {
        $this->signIn();

        foreach (['tags' => Tag::class, 'labels' => Label::class] as $routes => $class) {
            $this->post(route("{$routes}.store"), [
                'name' => 'جديد',
                'slug' => "new-{$routes}",
                'text_color' => '#ffffff',
                'background_color' => '#000000',
            ])->assertSessionHas('success');

            $record = $class::query()->where('slug', "new-{$routes}")->firstOrFail();
            $this->assertSame('جديد', $record->translate('ar')->name);

            $this->put(route("{$routes}.update", $record->id), [
                'name' => 'جديد',
                'slug' => "new-{$routes}",
                'text_color' => '#ff0000',
                'background_color' => '#00ff00',
            ])->assertSessionHas('success');

            $this->assertSame('#ff0000', $record->refresh()->text_color);
            $this->assertSame('#00ff00', $record->background_color);

            // يكتب في سمة style: لون سداسي فقط.
            $this->post(route("{$routes}.store"), [
                'name' => 'خطأ',
                'slug' => "bad-{$routes}",
                'text_color' => 'red;position:fixed',
                'background_color' => '#000000',
            ])->assertSessionHasErrors('text_color');
        }
    }

    public function test_a_unit_slug_is_made_from_the_name_and_must_be_unique(): void
    {
        $this->signIn();

        $this->post(route('units.store'), ['name' => 'Square Meter', 'slug' => ''])->assertSessionHas('success');
        $this->assertTrue(Unit::query()->where('slug', 'square-meter')->exists());

        $this->post(route('units.store'), ['name' => 'متر مربع', 'slug' => 'square-meter'])->assertSessionHasErrors('slug');
    }

    public function test_a_brand_or_unit_used_by_an_item_is_not_deleted(): void
    {
        $this->signIn();
        $brand = $this->record(Brand::class, 'كراون');
        $unit = $this->record(Unit::class, 'متر');
        $this->product(['brand_id' => $brand->id, 'unit_id' => $unit->id])->delete(); // محذوف حذفاً ناعماً، وما زال يمنع

        $this->delete(route('brands.destroy', $brand->id))->assertSessionHas('error', __('This brand is used by items and cannot be deleted.'));
        $this->assertNotNull($brand->fresh());

        $this->delete(route('units.destroy', $unit->id))->assertSessionHas('error', __('This unit is used by items and cannot be deleted.'));
        $this->assertNotNull($unit->fresh());

        // الحذف الجماعي يحذف غير المستخدم ويبقي المستخدم.
        $freeBrand = $this->record(Brand::class, 'حرة');
        $this->delete(route('brands.destroy-many'), ['ids' => [$brand->id, $freeBrand->id]])
            ->assertSessionHas('warning', __(':deleted deleted, :skipped not deleted. :reason', [
                'deleted' => 1, 'skipped' => 1, 'reason' => __('This brand is used by items and cannot be deleted.'),
            ]));
        $this->assertNotNull($brand->fresh());
        $this->assertNull($freeBrand->fresh());

        $freeUnit = $this->record(Unit::class, 'لتر');
        $this->delete(route('units.destroy-many'), ['ids' => [$unit->id, $freeUnit->id]])
            ->assertSessionHas('warning', __(':deleted deleted, :skipped not deleted. :reason', [
                'deleted' => 1, 'skipped' => 1, 'reason' => __('This unit is used by items and cannot be deleted.'),
            ]));
        $this->assertNotNull($unit->fresh());
        $this->assertNull($freeUnit->fresh());
    }

    public function test_unlinked_records_are_deleted_one_by_one_and_together(): void
    {
        $this->signIn();

        foreach (['brands' => Brand::class, 'tags' => Tag::class, 'labels' => Label::class, 'manufacturers' => Manufacturer::class, 'units' => Unit::class] as $routes => $class) {
            $one = $this->record($class, 'واحد');
            $this->delete(route("{$routes}.destroy", $one->id))->assertSessionHas('success');
            $this->assertNull($one->fresh());

            $first = $this->record($class, 'اول');
            $second = $this->record($class, 'ثان');
            $this->delete(route("{$routes}.destroy-many"), ['ids' => [$first->id, $second->id]])->assertSessionHas('success');
            $this->assertSame(0, $class::query()->count());
        }
    }

    public function test_a_tag_on_an_item_is_deleted_with_its_link(): void
    {
        $this->signIn();
        $tag = $this->record(Tag::class, 'جديد');
        $product = $this->product([]);
        $product->tags()->attach($tag->id);

        $this->delete(route('tags.destroy', $tag->id))->assertSessionHas('success');
        $this->assertNull($tag->fresh());
        $this->assertSame(0, $product->tags()->count());
    }

    public function test_info_returns_the_creator_and_the_activity_log(): void
    {
        $user = $this->signIn();
        $unit = $this->record(Unit::class, 'متر');

        $this->getJson(route('units.info', $unit->id))
            ->assertOk()
            ->assertJsonPath('created_by.name', $user->name)
            ->assertJsonPath('activities.0.event', 'created')
            ->assertJsonPath('activities.0.causer.id', $user->id)
            ->assertJsonStructure([
                'created_by' => ['id', 'name', 'avatar', 'detail'],
                'created_at',
                'updated_at',
                'activities' => ['*' => ['id', 'event', 'description', 'causer', 'changes' => ['*' => ['field', 'label', 'old', 'new', 'changed']], 'created_at', 'created_ago']],
            ]);
    }

    public function test_each_list_shows_who_added_the_row_and_is_ordered_by_hand(): void
    {
        $user = $this->signIn();
        $first = $this->record(Tag::class, 'أول');
        $second = $this->record(Tag::class, 'ثان');
        $third = $this->record(Tag::class, 'ثالث');

        // A new row goes last.
        $this->assertTrue($first->sort_order < $second->sort_order && $second->sort_order < $third->sort_order);

        $this->get(route('tags.index'))->assertInertia(fn (Assert $page) => $page
            ->where('table.reorder', 'sort_order')
            ->where('table.query.sort', 'sort_order')
            ->where('table.data.0.id', $first->id)
            ->where('table.data.0.created_by.name', $user->name));

        $this->post(route('tags.reorder'), ['ids' => [$third->id, $first->id, $second->id]])->assertSessionHas('success');

        $this->get(route('tags.index'))->assertInertia(fn (Assert $page) => $page
            ->where('table.data.0.id', $third->id)
            ->where('table.data.1.id', $first->id)
            ->where('table.data.2.id', $second->id));
    }

    public function test_every_action_needs_its_permission(): void
    {
        $this->signIn($this->permissions(['View List']));

        $records = [
            'brands' => $this->record(Brand::class, 'كراون'),
            'tags' => $this->record(Tag::class, 'جديد'),
            'labels' => $this->record(Label::class, 'عرض'),
            'manufacturers' => $this->record(Manufacturer::class, 'المصنع'),
            'units' => $this->record(Unit::class, 'متر'),
        ];

        foreach ($records as $routes => $record) {
            $this->get(route("{$routes}.index"))->assertOk();
            $this->post(route("{$routes}.store"), ['name' => 'س'])->assertForbidden();
            $this->put(route("{$routes}.update", $record->id), ['name' => 'س'])->assertForbidden();
            $this->delete(route("{$routes}.destroy", $record->id))->assertForbidden();
            $this->delete(route("{$routes}.destroy-many"), ['ids' => [$record->id]])->assertForbidden();
            // Info is basic: whoever sees the list sees a row's history.
            $this->getJson(route("{$routes}.info", $record->id))->assertOk();
            $this->assertNotNull($record->fresh());
        }

        $this->signIn([]);
        $this->get(route('brands.index'))->assertForbidden();
    }
}
