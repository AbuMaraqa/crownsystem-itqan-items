<?php

namespace Modules\Items\tests\Unit;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Modules\Items\Entities\ItemTypeProfile;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ItemBonusMode;
use Modules\Items\Enums\ItemDateMode;
use Modules\Items\Enums\ItemSerialMode;
use Modules\Items\Enums\ItemSubItemMode;
use Modules\Items\Enums\ProfileThickness;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * انواع الاصناف وحقول مقطع الالمنيوم في بطاقة الصنف.
 */
class ItemTypeProfilesTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function profile(array $attributes = []): ItemTypeProfile
    {
        return ItemTypeProfile::create(array_merge([
            'code' => 'type_'.Str::lower(Str::random(6)),
            'name' => 'نوع',
        ], $attributes));
    }

    private function product(array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'slug' => 'item-'.Str::lower(Str::random(8)),
            'base_price' => 0,
            'pricing_type' => 'simple',
        ], $attributes));
        $product->save();
        $product->translateOrNew('ar')->name = 'صنف '.$product->id;
        $product->save();

        return $product;
    }

    public function test_the_default_profile_is_seeded(): void
    {
        $profile = ItemTypeProfile::query()->where('code', 'a_store')->firstOrFail();

        $this->assertSame('مخزني', $profile->translate('ar')->name);
        $this->assertSame(2, $profile->quantity_decimals);
        $this->assertSame(2, $profile->price_decimals);
        $this->assertSame(ItemSubItemMode::NONE, $profile->sub_item_mode);
        $this->assertSame(ItemDateMode::NONE, $profile->date_mode);
        $this->assertSame(ItemBonusMode::NONE, $profile->bonus_mode);
        $this->assertSame(ItemSerialMode::NONE, $profile->serial_mode);
        $this->assertTrue($profile->is_active);
    }

    public function test_null_decimals_mean_a_hidden_field_and_zero_is_kept(): void
    {
        $profile = $this->profile(['quantity_decimals' => null, 'price_decimals' => 0]);

        $profile->refresh();

        $this->assertNull($profile->quantity_decimals);
        $this->assertSame(0, $profile->price_decimals);
    }

    public function test_enum_labels_are_arabic(): void
    {
        app()->setLocale('ar');

        $this->assertSame('ألمنيوم', ItemSubItemMode::ALUMINIUM->getLabel());
        $this->assertSame('سيريال بكمية', ItemSerialMode::SERIAL_WITH_QUANTITY->getLabel());
        $this->assertSame('رفيع', ProfileThickness::THIN->getLabel());
        $this->assertSame('خميل', ProfileThickness::THICK->getLabel());
    }

    public function test_assigning_an_aluminium_profile_sets_weight_pricing(): void
    {
        $product = $this->product();

        $product->update(['item_type_profile_id' => $this->profile(['sub_item_mode' => 'aluminium'])->id]);

        $this->assertSame('weight', $product->refresh()->pricing_type);
    }

    public function test_creating_with_an_aluminium_profile_sets_weight_pricing(): void
    {
        $product = $this->product(['item_type_profile_id' => $this->profile(['sub_item_mode' => 'aluminium'])->id]);

        $this->assertSame('weight', $product->refresh()->pricing_type);
    }

    public function test_an_aluminium_item_cannot_be_switched_to_simple_while_it_keeps_the_profile(): void
    {
        $product = $this->product(['item_type_profile_id' => $this->profile(['sub_item_mode' => 'aluminium'])->id]);

        $product->update(['pricing_type' => 'simple']);

        $this->assertSame('weight', $product->refresh()->pricing_type);
    }

    public function test_another_profile_leaves_the_pricing_type_alone(): void
    {
        $product = $this->product();

        $product->update(['item_type_profile_id' => $this->profile(['sub_item_mode' => 'assembly'])->id]);

        $this->assertSame('simple', $product->refresh()->pricing_type);
    }

    public function test_a_profile_in_use_cannot_be_deleted(): void
    {
        $profile = $this->profile();
        $this->product(['item_type_profile_id' => $profile->id]);

        $this->assertNotNull($profile->deletionBlocker());

        $this->expectException(DomainException::class);

        $profile->delete();
    }

    public function test_a_profile_used_only_by_a_deleted_item_still_cannot_be_deleted(): void
    {
        $profile = $this->profile();
        $this->product(['item_type_profile_id' => $profile->id])->delete();

        $this->assertNotNull($profile->deletionBlocker());
    }

    public function test_an_unused_profile_is_deleted_from_the_page(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $this->actingWith(['Item Type Profile View List', 'Item Type Profile Delete']);
        $profile = $this->profile();

        $this->delete(route('item-type-profiles.destroy', $profile->id))->assertSessionHas('success');

        $this->assertNull(ItemTypeProfile::query()->find($profile->id));
    }

    public function test_the_page_refuses_to_delete_a_profile_in_use(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $this->actingWith(['Item Type Profile View List', 'Item Type Profile Delete']);
        $profile = $this->profile();
        $product = $this->product(['item_type_profile_id' => $profile->id]);

        $this->delete(route('item-type-profiles.destroy', $profile->id))->assertSessionHas('error');

        $this->assertNotNull(ItemTypeProfile::query()->find($profile->id));
        $this->assertSame($profile->id, $product->refresh()->item_type_profile_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileForm(array $overrides = []): array
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

    public function test_the_page_creates_and_edits_a_profile(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $user = $this->actingWith(['Item Type Profile View List', 'Item Type Profile Create', 'Item Type Profile Update']);

        $this->post(route('item-type-profiles.store'), $this->profileForm())->assertSessionHasNoErrors();

        $profile = ItemTypeProfile::query()->where('code', 'al_custom')->firstOrFail();

        $this->assertSame('ألمنيوم خاص', $profile->name);
        $this->assertSame($user->id, $profile->created_by);
        $this->assertSame(3, $profile->quantity_decimals);
        $this->assertNull($profile->price_decimals);
        $this->assertSame(ItemSubItemMode::ALUMINIUM, $profile->sub_item_mode);
        $this->assertSame(ItemDateMode::LOT_DATE, $profile->date_mode);
        $this->assertTrue($profile->has_color);

        // النموذج يرسل كل حقوله، فما لم يتغير يبقى كما كان.
        $this->put(route('item-type-profiles.update', $profile->id), $this->profileForm(['name' => 'اسم جديد', 'sub_item_mode' => 'none']))
            ->assertSessionHasNoErrors();

        $profile->refresh();

        $this->assertSame('اسم جديد', $profile->name);
        $this->assertSame(ItemSubItemMode::NONE, $profile->sub_item_mode);
        $this->assertSame(3, $profile->quantity_decimals);
    }

    public function test_the_page_rejects_values_out_of_range_and_a_repeated_code(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $this->actingWith(['Item Type Profile View List', 'Item Type Profile Create']);

        $this->post(route('item-type-profiles.store'), $this->profileForm(['code' => 'a_store', 'name' => 'مكرر', 'quantity_decimals' => 6, 'dimensions' => 4, 'notes_count' => 4]))
            ->assertSessionHasErrors(['code', 'quantity_decimals', 'dimensions', 'notes_count']);

        $this->assertSame(1, ItemTypeProfile::query()->count());
    }

    /* ---------- بطاقة الصنف ---------- */

    /*
     * بقية حالات البطاقة في شجرة الاصناف (حفظ النوع وحقول الالمنيوم، وتحويل النوع الى الوزن، وبقاء
     * قيم الالمنيوم المخفية لصنف الكمية، وتفريغها الى null، وتكرار اسم المقطع) في
     * Modules/Items/tests/Feature/ItemTreePagesTest.php عبر HTTP.
     */

    public function test_the_item_card_accepts_a_barcode_used_by_another_item(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $this->actingWith(['Product View List', 'Product Create', 'Product Update']);
        $product = $this->product();
        app(\Modules\Items\Services\ProductBarcodeService::class)->save($this->product(), ['barcode' => 'SHARED-9']);

        $state = $this->getJson(route('products.tree.items.edit', $product->id))->assertOk()->json('state');

        $this->from(route('products.tree'))
            ->put(route('products.tree.items.update', $product->id), [...$state, 'barcode' => 'SHARED-9'])
            ->assertSessionHasNoErrors();

        $this->assertSame('SHARED-9', $product->refresh()->barcode);
    }
}
