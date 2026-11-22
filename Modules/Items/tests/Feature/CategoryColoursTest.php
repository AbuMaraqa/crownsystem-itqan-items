<?php

namespace Modules\Items\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
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
 * تبويب «الألوان وأسعار الكيلو» في صفحة التصنيف: ألوان التصنيف (مجموعات السعر) وسعر بيع الكيلو
 * وشرائه في كل قائمة سعر. اللون الجديد يُنشأ مع مرآته في سمة «اللون»، واللون المستخدم لا يُحذف.
 */
class CategoryColoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');

        foreach (['Category View List', 'Category Update'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function signIn(array $permissions = ['Category View List', 'Category Update']): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function category(string $pricingType = 'weight'): Category
    {
        $category = Category::create(['slug' => 'cat-'.Str::lower(Str::random(8)), 'status' => 1, 'pricing_type' => $pricingType]);
        $category->translateOrNew('ar')->name = 'الوبين';
        $category->save();

        return $category;
    }

    private function finish(string $name): Finish
    {
        $value = new AttributeValue(['attribute_id' => Finish::colourAttributeId()]);
        $value->translateOrNew('ar')->name = $name;
        $value->save();

        return Finish::create(['name' => $name, 'is_active' => true, 'attribute_value_id' => $value->id]);
    }

    private function save(Category $category, array $rows): \Illuminate\Testing\TestResponse
    {
        return $this->put(route('categories.colours.update', $category->id), ['rows' => $rows]);
    }

    public function test_the_edit_page_sends_the_colours_and_their_kg_prices(): void
    {
        $this->signIn();
        $category = $this->category();
        $white = $this->finish('أبيض');
        $this->finish('ميش');
        $group = CategoryFinish::create(['category_id' => $category->id, 'finish_id' => $white->id, 'is_active' => true]);
        $cash = PriceList::create(['name' => 'نقدي', 'is_active' => true, 'sort_order' => 1]);
        CategoryFinishPrice::create(['category_finish_id' => $group->id, 'price_list_id' => $cash->id, 'sell_price' => '22.5', 'buy_price' => '18']);

        $this->get(route('categories.edit', $category->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Category/Edit')
                ->where('category.pricing_type', 'weight')
                ->where('priceLists.0.name', 'نقدي')
                ->has('colours', 1)
                ->where('colours.0.id', $group->id)
                ->where('colours.0.name', 'أبيض')
                ->where('colours.0.used', 0)
                ->where('colours.0.prices.'.$cash->id.'.sell', '22.5')
                ->where('colours.0.prices.'.$cash->id.'.buy', '18')
                // «بلا لون» النظامي وأبيض وميش.
                ->has('finishes', 3));
    }

    public function test_colours_and_prices_are_saved(): void
    {
        $this->signIn();
        $category = $this->category();
        $white = $this->finish('أبيض');
        $mesh = $this->finish('ميش');
        $brown = $this->finish('بني');
        $cash = PriceList::create(['name' => 'نقدي', 'is_active' => true, 'sort_order' => 1]);
        $cheques = PriceList::create(['name' => 'شيكات', 'is_active' => true, 'sort_order' => 2]);
        $whiteGroup = CategoryFinish::create(['category_id' => $category->id, 'finish_id' => $white->id, 'is_active' => true]);
        $brownGroup = CategoryFinish::create(['category_id' => $category->id, 'finish_id' => $brown->id, 'is_active' => true]);
        CategoryFinishPrice::create(['category_finish_id' => $whiteGroup->id, 'price_list_id' => $cash->id, 'sell_price' => '20', 'buy_price' => '15', 'source_price_list_id' => $cheques->id, 'source_factor' => '1.1']);
        CategoryFinishPrice::create(['category_finish_id' => $whiteGroup->id, 'price_list_id' => $cheques->id, 'sell_price' => '21', 'buy_price' => '16']);

        $this->save($category, [
            // أبيض: سعر نقدي جديد، وسعر الشيكات يُمسح، ويُعطَّل.
            ['id' => $whiteGroup->id, 'finish_id' => $white->id, 'is_active' => false, 'prices' => [
                $cash->id => ['sell' => '22.5', 'buy' => '17'],
                $cheques->id => ['sell' => '', 'buy' => ''],
            ]],
            // ميش: لون موجود يُضاف.
            ['id' => null, 'finish_id' => $mesh->id, 'is_active' => true, 'prices' => [$cash->id => ['sell' => '30', 'buy' => '']]],
            // لون جديد بالاسم.
            ['id' => null, 'finish_id' => null, 'name' => 'رال 7016', 'is_active' => true, 'prices' => []],
            // بني: لم يعد في القائمة ولا صنف عليه، فيُحذف.
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $whiteGroup->refresh();
        $this->assertFalse($whiteGroup->is_active);
        $cashPrice = CategoryFinishPrice::query()->where(['category_finish_id' => $whiteGroup->id, 'price_list_id' => $cash->id])->firstOrFail();
        $this->assertSame('22.5000', $cashPrice->sell_price);
        $this->assertSame('17.0000', $cashPrice->buy_price);
        // سعر كُتب باليد لم يعد مشتقاً من قائمة اخرى.
        $this->assertNull($cashPrice->source_price_list_id);
        $this->assertFalse(CategoryFinishPrice::query()->where(['category_finish_id' => $whiteGroup->id, 'price_list_id' => $cheques->id])->exists());

        $meshGroup = CategoryFinish::query()->where(['category_id' => $category->id, 'finish_id' => $mesh->id])->firstOrFail();
        $this->assertSame('30.0000', $meshGroup->prices()->firstOrFail()->sell_price);
        // جانب فارغ صفر: العمود بلا null.
        $this->assertSame('0.0000', $meshGroup->prices()->firstOrFail()->buy_price);

        $ral = Finish::query()->where('name', 'رال 7016')->firstOrFail();
        $this->assertNotNull($ral->attribute_value_id);
        $this->assertSame(Finish::colourAttributeId(), (int) $ral->attributeValue->attribute_id);
        $this->assertTrue(CategoryFinish::query()->where(['category_id' => $category->id, 'finish_id' => $ral->id])->exists());

        $this->assertNull(CategoryFinish::query()->find($brownGroup->id));
    }

    public function test_a_colour_used_by_items_is_not_removed(): void
    {
        $this->signIn();
        $category = $this->category();
        $white = $this->finish('أبيض');
        $group = CategoryFinish::create(['category_id' => $category->id, 'finish_id' => $white->id, 'is_active' => true]);
        $item = new Product(['slug' => 'v-'.Str::lower(Str::random(6)), 'base_price' => 0, 'pricing_type' => 'weight', 'category_finish_id' => $group->id, 'finish_id' => $white->id]);
        $item->save();

        $this->save($category, [])->assertSessionHasErrors('rows');

        $this->assertNotNull(CategoryFinish::query()->find($group->id));
    }

    public function test_a_colour_appears_once_and_prices_must_be_numbers(): void
    {
        $this->signIn();
        $category = $this->category();
        $white = $this->finish('أبيض');
        $cash = PriceList::create(['name' => 'نقدي', 'is_active' => true, 'sort_order' => 1]);

        $this->save($category, [
            ['id' => null, 'finish_id' => $white->id, 'is_active' => true, 'prices' => []],
            ['id' => null, 'finish_id' => $white->id, 'is_active' => true, 'prices' => []],
        ])->assertSessionHasErrors('rows.1.finish_id');

        $this->save($category, [
            ['id' => null, 'finish_id' => $white->id, 'is_active' => true, 'prices' => [$cash->id => ['sell' => 'abc', 'buy' => '-1']]],
        ])->assertSessionHasErrors(["rows.0.prices.{$cash->id}.sell", "rows.0.prices.{$cash->id}.buy"]);

        $this->save($category, [['id' => null, 'finish_id' => null, 'name' => '', 'is_active' => true, 'prices' => []]])
            ->assertSessionHasErrors('rows.0.name');
    }

    public function test_saving_colours_needs_the_update_permission(): void
    {
        $category = $this->category();

        $this->signIn(['Category View List']);
        $this->save($category, [])->assertForbidden();
    }

    public function test_the_category_form_saves_its_pricing_type(): void
    {
        $this->signIn();
        $category = $this->category('simple');

        $this->put(route('categories.update', $category->id), ['name' => 'الوبين', 'slug' => $category->slug, 'pricing_type' => 'weight'])
            ->assertSessionHasNoErrors();

        $this->assertSame('weight', $category->refresh()->pricing_type);

        $this->put(route('categories.update', $category->id), ['name' => 'الوبين', 'slug' => $category->slug, 'pricing_type' => 'kg'])
            ->assertSessionHasErrors('pricing_type');
    }
}
