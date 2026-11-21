<?php

namespace Modules\Items\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Modules\GeoLocation\Entities\City;
use Modules\GeoLocation\Entities\Country;
use Modules\GeoLocation\Entities\Governorate;
use Modules\Items\Entities\Addon;
use Modules\Items\Entities\AddonOption;
use Modules\Items\Entities\Attribute;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Offer;
use Modules\Items\Enums\AddonType;
use Modules\Items\Enums\AttributeType;
use Modules\Items\Enums\OfferType;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * صفحات الخصائص والاضافات والعروض في لوحة Vue: القائمة والاضافة والتعديل والحذف، كلها بصلاحياتها.
 */
class AttributeAddonOfferPagesTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = ['View List', 'Create', 'Update', 'Delete', 'View', 'Info'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');
        Storage::fake('addon_option');
        Storage::fake('offers');

        foreach ($this->permissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    /**
     * @return list<string>
     */
    private function permissions(?array $entities = null, array $actions = self::ACTIONS): array
    {
        $names = [];

        foreach ($entities ?? ['Attribute', 'Addon', 'Offer'] as $entity) {
            foreach ($actions as $action) {
                $names[] = "$entity $action";
            }
        }

        return $names;
    }

    private function signIn(?array $permissions = null): User
    {
        $this->user = User::factory()->create();
        $this->user->givePermissionTo($permissions ?? $this->permissions());
        $this->actingAs($this->user);

        return $this->user;
    }

    // ----- Attributes ---------------------------------------------------------------------------

    private function attribute(string $slug = 'color', int $type = 3): Attribute
    {
        $attribute = new Attribute(['slug' => $slug, 'type' => $type]);
        $attribute->translateOrNew('ar')->name = 'اللون';
        $attribute->save();

        return $attribute;
    }

    public function test_the_attributes_list_is_an_inertia_table(): void
    {
        $this->signIn();
        $this->attribute('color');
        $this->attribute('size', AttributeType::SELECT->value);

        $this->get(route('attributes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Attribute/Index')
                ->has('table.data', 2)
                ->where('table.meta.total', 2)
                ->has('types', count(AttributeType::cases())));

        $this->get(route('attributes.index', ['filter' => ['type' => AttributeType::SELECT->value]]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 1)->where('table.data.0.slug', 'size'));
    }

    public function test_an_attribute_is_added_with_its_values(): void
    {
        $user = $this->signIn();

        $this->post(route('attributes.store'), [
            'name' => 'اللون',
            'slug' => '',
            'type' => AttributeType::COLOR->value,
            'description' => 'لون المنتج',
            'values' => [
                ['name' => 'أحمر', 'description' => 'أحمر داكن', 'color_code' => '#ff0000'],
                ['name' => 'أزرق', 'description' => null, 'color_code' => '#0000ff'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('attributes.index'));

        $attribute = Attribute::query()->with('attributeValues')->firstOrFail();
        $this->assertSame('اللون', $attribute->translate('ar')->name);
        $this->assertNotSame('', $attribute->slug); // made from the name
        $this->assertSame($user->id, (int) $attribute->created_by);

        $values = $attribute->attributeValues->sortBy('sort_order')->values();
        $this->assertCount(2, $values);
        // Each value keeps its own name, not the attribute's from the request.
        $this->assertSame('أحمر', $values[0]->translate('ar')->name);
        $this->assertSame('#ff0000', $values[0]->color_code);
        $this->assertSame('أزرق', $values[1]->translate('ar')->name);
    }

    public function test_editing_an_attribute_syncs_its_values(): void
    {
        $this->signIn();
        $attribute = $this->attribute();
        $kept = $attribute->attributeValues()->create(['color_code' => '#111111']);
        $kept->translateOrNew('ar')->name = 'أسود';
        $kept->save();
        $dropped = $attribute->attributeValues()->create(['color_code' => '#ffffff']);
        $dropped->translateOrNew('ar')->name = 'أبيض';
        $dropped->save();

        $this->get(route('attributes.edit', $attribute->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Attribute/Edit')
                ->where('attribute.slug', 'color')
                ->has('attribute.values', 2));

        $this->put(route('attributes.update', $attribute->id), [
            'name' => 'اللون',
            'slug' => 'colour',
            'type' => AttributeType::COLOR->value,
            'values' => [
                ['name' => 'أخضر', 'color_code' => '#00ff00'],
                ['id' => $kept->id, 'name' => 'أسود فاحم', 'color_code' => '#000000'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('attributes.index'));

        $this->assertSame(1, Attribute::query()->count());
        $this->assertSame('colour', $attribute->refresh()->slug);
        $this->assertNull($dropped->fresh());
        $this->assertSame('أسود فاحم', $kept->fresh()->translate('ar')->name);
        $this->assertSame(2, $attribute->attributeValues()->count());
    }

    public function test_a_value_of_another_attribute_cannot_be_taken_over(): void
    {
        $this->signIn();
        $attribute = $this->attribute('color');
        $other = $this->attribute('size');
        $foreign = $other->attributeValues()->create([]);

        $this->put(route('attributes.update', $attribute->id), [
            'name' => 'اللون', 'slug' => 'color', 'type' => AttributeType::COLOR->value,
            'values' => [['id' => $foreign->id, 'name' => 'x']],
        ])->assertSessionHasErrors('values.0.id');
    }

    public function test_an_attribute_slug_must_be_unique(): void
    {
        $this->signIn();
        $this->attribute('color');

        $this->post(route('attributes.store'), ['name' => 'لون', 'slug' => 'color', 'type' => AttributeType::COLOR->value])
            ->assertSessionHasErrors('slug');
    }

    public function test_attributes_are_deleted(): void
    {
        $this->signIn();
        $one = $this->attribute('a');
        $two = $this->attribute('b');
        $three = $this->attribute('c');

        $this->delete(route('attributes.destroy', $one->id))->assertSessionHas('success');
        $this->assertNull($one->fresh());

        $this->delete(route('attributes.destroy-many'), ['ids' => [$two->id, $three->id]])->assertSessionHas('success');
        $this->assertSame(0, Attribute::query()->count());
    }

    // ----- Addons -------------------------------------------------------------------------------

    private function addonPayload(array $overrides = []): array
    {
        return [
            'name' => 'الصلصات',
            'type' => AddonType::CHECKBOX->value,
            'description' => 'صلصات اضافية',
            'options' => [
                [
                    'name' => 'صلصة حارة',
                    'price' => '2.50',
                    'is_default' => '1',
                    'is_quantifiable' => '0',
                    'description' => 'حارة جداً',
                    'image' => UploadedFile::fake()->image('hot.png', 400, 400),
                ],
            ],
            ...$overrides,
        ];
    }

    public function test_the_addons_list_is_an_inertia_table(): void
    {
        $this->signIn();
        $this->post(route('addons.store'), $this->addonPayload())->assertSessionHasNoErrors();

        $this->get(route('addons.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Addon/Index')
                ->has('table.data', 1)
                ->where('table.data.0.name', 'الصلصات'));
    }

    public function test_an_addon_is_added_with_its_options(): void
    {
        $user = $this->signIn();

        $this->post(route('addons.store'), $this->addonPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('addons.index'));

        $addon = Addon::query()->with('addonOptions')->firstOrFail();
        $this->assertSame('الصلصات', $addon->translate('ar')->name);
        $this->assertSame(AddonType::CHECKBOX, $addon->type);
        $this->assertSame($user->id, (int) $addon->created_by);

        $option = $addon->addonOptions->first();
        // The option keeps its own name, not the addon's from the request.
        $this->assertSame('صلصة حارة', $option->translate('ar')->name);
        $this->assertTrue((bool) $option->is_default);
        $this->assertNotNull($option->getFirstMedia('addon_option'));
    }

    public function test_a_new_option_needs_an_image(): void
    {
        $this->signIn();

        $payload = $this->addonPayload();
        unset($payload['options'][0]['image']);

        $this->post(route('addons.store'), $payload)->assertSessionHasErrors('options.0.image');
    }

    public function test_editing_an_addon_keeps_a_saved_option_image(): void
    {
        $this->signIn();
        $this->post(route('addons.store'), $this->addonPayload())->assertSessionHasNoErrors();
        $addon = Addon::query()->firstOrFail();
        $option = $addon->addonOptions()->firstOrFail();

        $this->get(route('addons.edit', $addon->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Addon/Edit')
                ->has('addon.options', 1)
                ->has('addon.options.0.images', 1));

        $this->put(route('addons.update', $addon->id), [
            'name' => 'الصلصات',
            'type' => AddonType::RADIO->value,
            'options' => [['id' => $option->id, 'name' => 'صلصة خفيفة', 'price' => '3']],
        ])->assertSessionHasNoErrors()->assertRedirect(route('addons.index'));

        $this->assertSame(AddonType::RADIO, $addon->refresh()->type);
        $this->assertSame('صلصة خفيفة', $option->fresh()->translate('ar')->name);
        $this->assertSame(1, AddonOption::query()->count());
    }

    public function test_addons_are_deleted(): void
    {
        $this->signIn();
        $this->post(route('addons.store'), $this->addonPayload())->assertSessionHasNoErrors();
        $addon = Addon::query()->firstOrFail();

        $this->delete(route('addons.destroy', $addon->id))->assertSessionHas('success');
        $this->assertNull(Addon::query()->find($addon->id));
    }

    // ----- Offers -------------------------------------------------------------------------------

    private function branch(string $name = 'فرع الخليل'): Branch
    {
        $currency = Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'دولار', 'symbol' => '$', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => 1, 'is_active' => true,
        ]);
        $country = Country::query()->firstOrCreate(['code' => 'PS'], ['name' => 'فلسطين', 'phone_code' => '970', 'currency_id' => $currency->id]);
        $governorate = Governorate::query()->firstOrCreate(['code' => 'HBN'], ['name' => 'الخليل', 'country_id' => $country->id]);
        $city = City::query()->firstOrCreate(['governorate_id' => $governorate->id], [
            'name' => 'دورا', 'latitude' => 31.5, 'longitude' => 35.1, 'population' => 1000, 'type' => 1,
        ]);

        return Branch::query()->create([
            'name' => $name, 'country_id' => $country->id, 'city_id' => $city->id, 'created_by' => $this->user->id,
        ]);
    }

    private function category(): Category
    {
        return Category::create(['slug' => 'offers-'.uniqid(), 'status' => 1]);
    }

    private function offerPayload(array $overrides = []): array
    {
        return [
            'name' => 'خصم الصيف',
            'description' => 'خصم على التصنيف',
            'branch_id' => [$this->branch()->id],
            'offerable_model' => 'items_category',
            'offerable_id' => $this->category()->id,
            'type' => OfferType::PERCENTAGE->value,
            'value' => '10',
            'code' => '',
            'min_purchase_amount' => '0',
            'usage_limit' => '100',
            'usage_limit_per_user' => '1',
            'start_date' => '2026-01-01T08:00',
            'end_date' => '2026-02-01T08:00',
            'is_active' => '1',
            'image' => UploadedFile::fake()->image('offer.png', 400, 400),
            ...$overrides,
        ];
    }

    public function test_an_offer_is_added_once_per_branch_with_the_same_image(): void
    {
        $this->signIn();
        $second = $this->branch('فرع دورا');

        $payload = $this->offerPayload();
        $payload['branch_id'][] = $second->id;

        $this->post(route('offers.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('offers.index'));

        $offers = Offer::query()->orderBy('id')->get();
        $this->assertCount(2, $offers);
        $this->assertSame($second->id, (int) $offers[1]->branch_id);
        $this->assertSame(Category::class, $offers[0]->offerable_type);
        $this->assertSame('خصم الصيف', $offers[1]->translate('ar')->name);
        $this->assertNotNull($offers[0]->getFirstMedia('offer'));
        $this->assertNotNull($offers[1]->getFirstMedia('offer'));

        $this->get(route('offers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Items/Offer/Index')->has('table.data', 2)->has('branches', 2));
    }

    public function test_an_offer_code_cannot_be_shared_by_several_branches(): void
    {
        $this->signIn();
        $payload = $this->offerPayload(['code' => 'SUMMER']);
        $payload['branch_id'][] = $this->branch('فرع دورا')->id;

        $this->post(route('offers.store'), $payload)->assertSessionHasErrors('code');
        $this->assertSame(0, Offer::query()->count());
    }

    public function test_an_offer_target_must_exist(): void
    {
        $this->signIn();

        $this->post(route('offers.store'), $this->offerPayload(['offerable_id' => 999999]))->assertSessionHasErrors('offerable_id');
        $this->post(route('offers.store'), $this->offerPayload(['image' => null]))->assertSessionHasErrors('image');
    }

    public function test_editing_an_offer_updates_it_and_keeps_its_image(): void
    {
        $this->signIn();
        $this->post(route('offers.store'), $this->offerPayload())->assertSessionHasNoErrors();
        $offer = Offer::query()->firstOrFail();

        $this->get(route('offers.edit', $offer->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Offer/Edit')
                ->where('offer.offerable_model', 'items_category')
                ->where('target.value', (int) $offer->offerable_id)
                ->has('images', 1));

        $this->put(route('offers.update', $offer->id), [
            ...$this->offerPayload(['value' => '15', 'code' => 'VIP']),
            'branch_id' => $offer->branch_id,
            'offerable_id' => $offer->offerable_id,
            'image' => null,
        ])->assertSessionHasNoErrors()->assertRedirect(route('offers.index'));

        $offer->refresh();
        $this->assertSame(1, Offer::query()->count());
        $this->assertEquals(15, (float) $offer->value);
        $this->assertSame('VIP', $offer->code);
        $this->assertNotNull($offer->getFirstMedia('offer'));
    }

    public function test_the_offer_target_picker_lists_categories(): void
    {
        $this->signIn();
        $category = $this->category();

        $this->getJson(route('offers.targets', ['model' => 'items_category']))
            ->assertOk()
            ->assertJsonFragment(['value' => $category->id]);

        // Products are listed only for chosen branches.
        $this->getJson(route('offers.targets', ['model' => 'items_product']))->assertOk()->assertExactJson([]);
    }

    public function test_offers_are_deleted(): void
    {
        $this->signIn();
        $this->post(route('offers.store'), $this->offerPayload())->assertSessionHasNoErrors();
        $offer = Offer::query()->firstOrFail();

        $this->delete(route('offers.destroy', $offer->id))->assertSessionHas('success');
        $this->assertNull($offer->fresh());
    }

    // ----- Permissions --------------------------------------------------------------------------

    public function test_every_action_needs_its_permission(): void
    {
        $this->signIn($this->permissions(actions: ['View List']));
        $attribute = $this->attribute();

        $this->get(route('attributes.index'))->assertOk();
        $this->get(route('addons.index'))->assertOk();
        $this->get(route('offers.index'))->assertOk();

        $this->get(route('attributes.add'))->assertForbidden();
        $this->post(route('attributes.store'), ['name' => 'x', 'type' => 1])->assertForbidden();
        $this->get(route('attributes.edit', $attribute->id))->assertForbidden();
        $this->put(route('attributes.update', $attribute->id), ['name' => 'x', 'type' => 1])->assertForbidden();
        $this->delete(route('attributes.destroy', $attribute->id))->assertForbidden();
        $this->delete(route('attributes.destroy-many'), ['ids' => [$attribute->id]])->assertForbidden();
        // Info is basic: whoever sees the list sees the row's history (View List).
        $this->getJson(route('attributes.info', $attribute->id))->assertOk();

        $this->get(route('addons.add'))->assertForbidden();
        $this->post(route('addons.store'), [])->assertForbidden();
        $this->get(route('addons.edit', 1))->assertForbidden();
        $this->put(route('addons.update', 1), [])->assertForbidden();
        $this->delete(route('addons.destroy', 1))->assertForbidden();
        // Info passes the permission (View List); there is no addon 1.
        $this->getJson(route('addons.info', 1))->assertNotFound();

        $this->get(route('offers.add'))->assertForbidden();
        $this->post(route('offers.store'), [])->assertForbidden();
        $this->get(route('offers.edit', 1))->assertForbidden();
        $this->put(route('offers.update', 1), [])->assertForbidden();
        $this->delete(route('offers.destroy', 1))->assertForbidden();
        $this->getJson(route('offers.info', 1))->assertNotFound();
        $this->getJson(route('offers.targets', ['model' => 'items_category']))->assertForbidden();
        $this->getJson(route('offers.edit-targets', ['model' => 'items_category']))->assertForbidden();

        $this->assertNotNull($attribute->fresh());
    }

    public function test_the_info_action_shows_who_created_the_record(): void
    {
        $user = $this->signIn();
        $this->post(route('addons.store'), $this->addonPayload())->assertSessionHasNoErrors();
        $addon = Addon::query()->firstOrFail();

        $this->getJson(route('addons.info', $addon->id))
            ->assertOk()
            ->assertJsonPath('created_by.name', $user->name);
    }
}
