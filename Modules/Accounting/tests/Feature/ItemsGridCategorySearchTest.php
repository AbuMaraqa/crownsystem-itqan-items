<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\tests\Unit\ConsolidatedTestFactory;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Support\Search\ItemsSearchIndex;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * كما في النظام القديم: خانة البحث في جدول الاصناف تُظهر التصنيفات مع الاصناف، فيختار المستخدم
 * التصنيف اولاً فيصير رأس مجموعة، ويضيف تحته اصنافه (والبحث داخله يقتصر على اصناف التصنيف).
 */
class ItemsGridCategorySearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('Purchase Order Create', 'web'));
        $this->actingAs($user);
    }

    private function category(string $name): Category
    {
        $category = Category::create(['slug' => 'cat-'.uniqid(), 'status' => 1]);
        $category->translateOrNew('ar')->name = $name;
        $category->save();

        return $category;
    }

    /**
     * «اكسسوارات الوبين» وبداخله «مفصلة الوبين».
     *
     * @return array{0: Category, 1: \Modules\Items\Entities\Product}
     */
    private function accessoriesCategory(): array
    {
        $category = $this->category('اكسسوارات الوبين');
        $item = ConsolidatedTestFactory::product();
        $item->translateOrNew('ar')->name = 'مفصلة الوبين';
        $item->save();
        $item->categories()->attach($category->id);
        app(ItemsSearchIndex::class)->flush();

        return [$category, $item];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(string $term, ?int $categoryId = null): array
    {
        return $this->getJson(route('accounting.items-grid.products', array_filter([
            'document' => 'purchase-order',
            'q' => $term,
            'category_id' => $categoryId,
        ])))->assertOk()->json();
    }

    public function test_the_item_search_offers_matching_categories_before_the_items(): void
    {
        [$category, $item] = $this->accessoriesCategory();

        $results = $this->search('الوبين');

        $this->assertSame('category', $results[0]['kind']);
        $this->assertSame($category->id, $results[0]['id']);
        $this->assertSame('اكسسوارات الوبين', $results[0]['label']);
        $this->assertContains($item->id, collect($results)->where('kind', 'item')->pluck('id')->all());
    }

    public function test_inside_a_category_row_the_search_offers_only_its_items(): void
    {
        [$category, $item] = $this->accessoriesCategory();

        $results = $this->search('الوبين', $category->id);

        $this->assertSame([], collect($results)->where('kind', 'category')->all());
        $this->assertSame([$item->id], collect($results)->pluck('id')->all());
    }
}
