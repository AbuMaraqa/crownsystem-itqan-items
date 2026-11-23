<?php

namespace Modules\Items\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Attribute;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\Finish;
use Modules\Items\Services\FinishService;
use Modules\Items\Support\ItemModelPermissions;
use Tests\TestCase;

/**
 * صفحة «الألوان»: قائمة الألوان الواحدة لكل الماركات. كل لون مصدر قيمته في سمة «اللون» التي يقرأ
 * منها المتجر، فالاسم ولون العرض يُكتبان فيها معه. «بلا لون» النظامي لا يُحذف ولا يُعطَّل، واللون
 * المستخدم في تصنيف او صنف لا يُحذف (يُعطَّل بدلاً من ذلك).
 */
class FinishPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        app()->setLocale('ar');
        ItemModelPermissions::sync();
    }

    private function signIn(array $actions = ['View List', 'Create', 'Update', 'Delete']): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (string $action): string => 'Finish '.$action, $actions));
        $this->actingAs($user);

        return $user;
    }

    public function test_colours_are_listed_added_and_edited_with_their_store_value(): void
    {
        $this->signIn();

        $this->get(route('finishes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Items/Finish/Index')->where('table.meta.total', 1)->where('table.data.0.is_system', true));

        $this->post(route('finishes.store'), ['name' => 'أبيض', 'code' => 'W', 'color_code' => '#ffffff', 'is_active' => true])->assertSessionHas('success');
        $white = Finish::query()->where('name', 'أبيض')->sole();
        $this->assertSame('W', $white->code);
        $this->assertSame('أبيض', (string) $white->attributeValue->name);
        $this->assertSame('#ffffff', $white->attributeValue->color_code);
        $this->assertSame(Finish::colourAttributeId(), (int) $white->attributeValue->attribute_id);

        // الاسم مكرر مرفوض.
        $this->post(route('finishes.store'), ['name' => 'أبيض'])->assertSessionHasErrors('name');

        $this->put(route('finishes.update', $white->id), ['name' => 'أبيض ناصع', 'code' => 'W1', 'color_code' => '#fafafa', 'is_active' => false])->assertSessionHas('success');
        $white->refresh();
        $this->assertSame('أبيض ناصع', $white->name);
        $this->assertFalse($white->is_active);
        $this->assertSame('أبيض ناصع', (string) $white->attributeValue->refresh()->name);
        $this->assertSame('#fafafa', $white->attributeValue->color_code);
        $this->assertArrayNotHasKey($white->id, Finish::options());
    }

    public function test_a_colour_is_numbered_like_itqan_and_the_number_can_be_changed(): void
    {
        $this->signIn();
        $code = fn (string $name): ?string => Finish::withTrashed()->where('name', $name)->value('code');

        // «بلا لون» أول الألوان، فرقمه 001، والنموذج يقترح الرقم التالي.
        $this->assertSame('001', Finish::system()->code);
        $this->get(route('finishes.index'))->assertInertia(fn (Assert $page) => $page->where('nextCode', '002'));

        $this->post(route('finishes.store'), ['name' => 'أبيض'])->assertSessionHas('success');
        $this->assertSame('002', $code('أبيض'));

        // رقم يكتبه المستخدم يبقى، والتلقائي بعده يكمل من اكبر رقم.
        $this->post(route('finishes.store'), ['name' => 'ميش', 'code' => '010'])->assertSessionHas('success');
        $this->post(route('finishes.store'), ['name' => 'أسود'])->assertSessionHas('success');
        $this->assertSame('010', $code('ميش'));
        $this->assertSame('011', $code('أسود'));

        // الرقم لا يتكرر، وتغييره مسموح، وتفريغه يُبقي رقمه.
        $this->post(route('finishes.store'), ['name' => 'رمادي', 'code' => '010'])->assertSessionHasErrors('code');
        $white = Finish::query()->where('name', 'أبيض')->sole();
        $this->put(route('finishes.update', $white->id), ['name' => 'أبيض', 'code' => '005'])->assertSessionHas('success');
        $this->assertSame('005', $code('أبيض'));
        $this->put(route('finishes.update', $white->id), ['name' => 'أبيض', 'code' => ''])->assertSessionHas('success');
        $this->assertSame('005', $code('أبيض'));

        // رقم اللون المحذوف لا يُعاد، واللون الذي يُنشأ من صفحة التصنيف يُرقَّم كذلك.
        $this->delete(route('finishes.destroy', Finish::query()->where('name', 'أسود')->value('id')))->assertSessionHas('success');
        $this->assertSame('012', app(FinishService::class)->findOrCreate('بني', null)->code);
    }

    public function test_the_system_colour_and_used_colours_are_kept(): void
    {
        $this->signIn();
        $system = Finish::system();

        $this->delete(route('finishes.destroy', $system->id))->assertSessionHas('error');
        $this->put(route('finishes.update', $system->id), ['name' => $system->name, 'is_active' => false])->assertSessionHas('error');
        $this->assertTrue($system->refresh()->is_active);

        $this->post(route('finishes.store'), ['name' => 'ميش', 'is_active' => true]);
        $mesh = Finish::query()->where('name', 'ميش')->sole();
        $category = Category::create(['slug' => 'cat-'.Str::lower(Str::random(8)), 'status' => 1, 'pricing_type' => 'weight']);
        CategoryFinish::create(['category_id' => $category->id, 'finish_id' => $mesh->id, 'is_active' => true]);

        $this->delete(route('finishes.destroy', $mesh->id))->assertSessionHas('error', __('This colour is used by categories or items; deactivate it instead.'));
        $this->assertNotNull($mesh->refresh());

        // غير المستخدم يُحذف مع قيمته في سمة «اللون».
        $this->post(route('finishes.store'), ['name' => 'رال 7016', 'is_active' => true]);
        $ral = Finish::query()->where('name', 'رال 7016')->sole();
        $valueId = $ral->attribute_value_id;
        $this->delete(route('finishes.destroy', $ral->id))->assertSessionHas('success');
        $this->assertSoftDeleted($ral);
        $this->assertNull(Attribute::query()->find(Finish::colourAttributeId())->attributeValues()->find($valueId));
    }

    public function test_every_action_needs_its_permission(): void
    {
        $this->signIn(['View List']);

        $this->get(route('finishes.index'))->assertOk();
        $this->post(route('finishes.store'), ['name' => 'أسود'])->assertForbidden();
        $this->put(route('finishes.update', Finish::system()->id), ['name' => 'x'])->assertForbidden();
        $this->delete(route('finishes.destroy', Finish::system()->id))->assertForbidden();
    }
}
