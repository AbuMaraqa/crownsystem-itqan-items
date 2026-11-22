<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Services\MainProductLinker;
use Modules\Items\Support\ProductSearch;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * شجرة اصناف الوزن: الصنف الفرعي يتبع صنفه الرئيسي، ويُربط من شجرة النظام
 * القديم، ويظهر في قائمة الاصناف داخل صنفه الرئيسي.
 */
class MainProductTreeTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, string $sku, string $pricingType = 'weight', array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'slug' => Str::slug($sku).'-'.Str::random(6),
            'sku' => $sku,
            'barcode' => 'BC-'.$sku,
            'base_price' => 0,
            'pricing_type' => $pricingType,
        ], $attributes));

        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    private function legacySubItem(string $name, string $sku, string $parentLegacyId, string $pricingType = 'weight'): Product
    {
        return $this->product($name, $sku, $pricingType, ['meta_data' => ['legacy_parent_id' => $parentLegacyId]]);
    }

    public function test_sub_items_are_linked_to_their_main_item_from_the_old_tree(): void
    {
        $main = $this->product('المنيوم سبير F40 SAPIR', '00088');
        $sub = $this->legacySubItem('كباس زجاج ثابت', 'BLT5227', '00088');
        $quantityItem = $this->legacySubItem('مقبض', 'H-1', '00088', 'simple');

        $dryRun = app(MainProductLinker::class)->link(dryRun: true);

        $this->assertSame(1, $dryRun['stats']['linked']);
        $this->assertNull($sub->fresh()->main_product_id);

        app(MainProductLinker::class)->link(dryRun: false);

        $this->assertSame($main->id, $sub->fresh()->main_product_id);
        // صنف الكمية ليس صنف وزن فرعياً.
        $this->assertNull($quantityItem->fresh()->main_product_id);
        $this->assertTrue($main->subProducts()->whereKey($sub->id)->exists());
    }

    public function test_a_main_item_imported_as_a_category_is_reported_or_created(): void
    {
        $category = Category::create(['slug' => 'old-cat-00301', 'status' => 1]);
        $category->translateOrNew('ar')->name = 'المنيوم سبير sapir 1200';
        $category->save();

        $sub = $this->legacySubItem('ثابت 3 مجاري', 'K4593', '00301');
        $orphan = $this->legacySubItem('مقطع بلا اب', 'X-1', '99999');

        $report = app(MainProductLinker::class)->link(dryRun: false);

        $this->assertSame(1, $report['stats']['parent_is_category']);
        $this->assertSame(1, $report['stats']['parent_missing']);
        $this->assertSame(['parent_is_category', 'parent_missing'], array_column($report['unlinked'], 'reason'));
        $this->assertNull($sub->fresh()->main_product_id);

        $report = app(MainProductLinker::class)->link(dryRun: false, createFromCategories: true);

        $main = Product::query()->where('sku', '00301')->firstOrFail();

        $this->assertSame(1, $report['stats']['main_products_created']);
        $this->assertSame('المنيوم سبير sapir 1200', $main->name);
        $this->assertSame('weight', $main->pricing_type);
        $this->assertSame($main->id, $sub->fresh()->main_product_id);
        $this->assertNull($orphan->fresh()->main_product_id);
    }

    public function test_a_parent_that_is_not_priced_by_weight_is_not_a_main_item(): void
    {
        $this->product('اكسسوارات', 'ACC', 'simple');
        $sub = $this->legacySubItem('كباس', 'BLT1', 'ACC');

        $report = app(MainProductLinker::class)->link(dryRun: false);

        $this->assertSame(1, $report['stats']['parent_not_weight']);
        $this->assertNull($sub->fresh()->main_product_id);
    }

    public function test_sub_product_search_is_limited_to_the_main_item(): void
    {
        $main = $this->product('المنيوم سبير F40 SAPIR', '00088');
        $other = $this->product('المنيوم نابكو', '00090');
        $sub = $this->product('كباس زجاج ثابت', 'BLT5227', attributes: ['main_product_id' => $main->id]);
        $this->product('كباس زجاج نابكو', 'BLT9000', attributes: ['main_product_id' => $other->id]);

        $this->assertSame([$sub->id], array_keys(ProductSearch::subProductOptions($main->id, 'زجاج')));
        $this->assertSame([$sub->id], array_keys(ProductSearch::subProductOptions($main->id)));
        $this->assertSame([$main->id, $other->id], ProductSearch::idsWithSubProducts([$main->id, $other->id, $sub->id]));
        $this->assertSame([$sub->id => '00088 - المنيوم سبير F40 SAPIR'], ProductSearch::mainProductLabels([$sub->id, $main->id]));

        $payload = ProductSearch::invoiceItemPayload($sub->id);
        $this->assertSame($main->id, $payload['main_product_id']);
        $this->assertTrue(ProductSearch::invoiceItemPayload($main->id)['has_sub_products']);
    }

    /**
     * صفحات Vue: بلا تحويل اللغة، وبصلاحيات الصنف.
     */
    private function signInForProductPages(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        config(['broadcasting.default' => 'null']);

        $user = User::factory()->create();

        foreach (['Product View List', 'Product Update'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $this->actingAs($user);
    }

    public function test_the_main_item_search_lists_weight_items_only(): void
    {
        $this->signInForProductPages();

        $main = $this->product('المنيوم سبير F40 SAPIR', '00088');

        // البحث عن الصنف الرئيسي (شجرة الاصناف) لا يعرض اصناف الكمية. بطاقة الصنف لم تعد تختاره.
        $this->product('المنيوم اكسسوارات', '00099', 'simple');
        $this->assertSame([$main->id], array_keys(ProductSearch::options('المنيوم', 25, 'weight')));
        $this->getJson(route('products.edit-lookup', ['type' => 'main', 'q' => 'المنيوم']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.value', $main->id);
    }

    public function test_items_list_shows_old_sub_items_as_ordinary_rows(): void
    {
        $this->signInForProductPages();

        $main = $this->product('المنيوم سبير F40 SAPIR', '00088');
        $sub = $this->product('كباس زجاج ثابت', 'BLT5227', attributes: ['main_product_id' => $main->id]);
        $door = $this->product('باب 2000 دفتين', 'D-2000', 'simple');

        $ids = fn ($response): array => collect($response->viewData('page')['props']['table']['data'])->pluck('id')->sort()->values()->all();

        // الصنف ينتمي الى تصنيفه فقط: لا صنف رئيسي يجمع غيره في القائمة.
        $this->assertSame(collect([$main->id, $sub->id, $door->id])->sort()->values()->all(), $ids($this->get(route('products.index'))->assertOk()));
        $this->assertSame([$sub->id], $ids($this->get(route('products.index', ['filter' => ['search' => 'BLT5227']]))));
    }
}
