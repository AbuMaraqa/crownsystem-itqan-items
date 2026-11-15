<?php

namespace Modules\Items\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\CategoryStatus;
use Modules\Items\Repositories\Contracts\CategoryRepositoryInterface;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * شجرة التصنيفات واصناف التصنيف في لوحة Vue: التحميل عند الطلب، البحث، اخفاء غير النشط،
 * السحب (الاب والترتيب ومنع الحلقات)، التفعيل والحذف، وجدول اصناف التصنيف.
 * (كانت CategoryTreeTest على صفحة Livewire؛ كل حالاتها هنا عبر HTTP.)
 */
class CategoryTreePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');
        $this->withoutMiddleware([\Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class, \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class]);
        $this->signIn(['Category View List', 'Category Update', 'Category Delete', 'Product View List']);
    }

    private function signIn(array $permissions): void
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $this->actingAs($user);
    }

    private function makeCategory(string $name, ?int $parentId = null, int $order = 0): Category
    {
        $category = new Category([
            'slug' => Str::slug($name).'-'.Str::random(6),
            'status' => CategoryStatus::ACTIVE->value(),
            'sort_order' => $order,
            'parent_id' => $parentId,
        ]);

        $category->save();
        $category->translateOrNew('ar')->name = $name;
        $category->save();

        return $category;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tree(array $query = []): array
    {
        $tree = [];

        $this->get(route('categories.tree', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$tree) {
                $page->component('Items/CategoryTree/Index');
                $tree = $page->toArray()['props']['tree'];
            });

        return $tree;
    }

    private function depth(array $nodes, int $level = 1): int
    {
        $max = $nodes ? $level : 0;

        foreach ($nodes as $node) {
            $max = max($max, $this->depth($node['children'], $level + 1));
        }

        return $max;
    }

    private function countNodes(array $nodes): int
    {
        $total = count($nodes);

        foreach ($nodes as $node) {
            $total += $this->countNodes($node['children']);
        }

        return $total;
    }

    private function siblingsOrder(int $parentId): array
    {
        return Category::query()->where('parent_id', $parentId)->orderBy('sort_order')->pluck('id')->all();
    }

    public function test_it_loads_root_categories_only_until_a_node_is_opened(): void
    {
        $root = $this->makeCategory('الكترونيات');
        $phones = $this->makeCategory('هواتف', $root->id);
        $this->makeCategory('اندرويد', $phones->id);
        $this->makeCategory('مواد بناء');

        $tree = $this->tree();

        // بلا فتح: الجذور فقط، فلا تُحمَّل الشجرة كاملة مع كل طلب للصفحة.
        $this->assertSame(2, $this->countNodes($tree));
        $this->assertSame(1, $this->depth($tree));
        // الجذر الذي له فروع يعرضها كسهم فتح دون جلبها.
        $this->assertTrue($tree[0]['has_children']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CategoryRepositoryInterface::class)->treeNodes(null, true);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $queries, 'تحميل الجذور يجب ان يبقى بعدد استعلامات ثابت.');
    }

    public function test_opening_a_node_loads_its_children_only(): void
    {
        $root = $this->makeCategory('الكترونيات');
        $phones = $this->makeCategory('هواتف', $root->id);
        $this->makeCategory('اندرويد', $phones->id);

        $branch = $this->getJson(route('categories.tree.children', $root->id))->assertOk()->json();

        // فرع واحد فقط نزل: ابن الجذر دون الاحفاد.
        $this->assertSame([$phones->id], array_column($branch['children'], 'id'));
        $this->assertSame([], $branch['children'][0]['children']);
        $this->assertTrue($branch['children'][0]['has_children']);
    }

    public function test_an_open_node_lists_its_items_in_batches(): void
    {
        $root = $this->makeCategory('ابواب');

        foreach (range(1, 52) as $index) {
            $product = new Product(['slug' => 'p-'.Str::random(8), 'sku' => 'S'.$index, 'base_price' => 0, 'pricing_type' => 'simple']);
            $product->save();
            $product->translateOrNew('ar')->name = 'باب '.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $product->save();
            $product->categories()->attach($root->id);
        }

        $this->assertSame(52, $this->tree()[0]['products_count']);
        $this->assertCount(50, $this->getJson(route('categories.tree.children', $root->id))->json('products'));
        $this->assertCount(52, $this->getJson(route('categories.tree.children', [$root->id, 'limit' => 100]))->json('products'));
        $this->assertSame('باب 01', $this->getJson(route('categories.tree.children', $root->id))->json('products.0.name'));
    }

    public function test_search_walks_the_whole_tree_and_keeps_the_ancestors_of_a_match(): void
    {
        $root = $this->makeCategory('الكترونيات');
        $phones = $this->makeCategory('هواتف', $root->id);
        $this->makeCategory('اندرويد', $phones->id);
        $this->makeCategory('مواد بناء');

        $result = $this->tree(['search' => 'اندرويد']);

        // البحث يتجاوز التحميل عند الطلب لان النتيجة قد تكون في اي مستوى.
        $this->assertCount(1, $result, 'الجذر غير المطابق يجب ان يُستبعد.');
        $this->assertSame(3, $this->depth($result), 'سلسلة الاجداد يجب ان تبقى.');
        $this->assertSame($root->id, $result[0]['id']);
    }

    public function test_inactive_categories_can_be_hidden(): void
    {
        $this->makeCategory('مفعّل');
        $hidden = $this->makeCategory('غير مفعّل');
        $hidden->status = CategoryStatus::INACTIVE;
        $hidden->save();

        $this->assertSame(1, $this->countNodes($this->tree(['show_inactive' => 0])));
        $this->assertSame(2, $this->countNodes($this->tree(['show_inactive' => 1])));
        $this->assertSame(2, $this->countNodes($this->tree()));
    }

    public function test_it_rejects_moving_a_category_into_itself_or_its_descendant(): void
    {
        $root = $this->makeCategory('جذر');
        $child = $this->makeCategory('ابن', $root->id);
        $grandChild = $this->makeCategory('حفيد', $child->id);

        $this->from(route('categories.tree'))
            ->put(route('categories.tree.move', $root->id), ['parent_id' => $root->id, 'position' => 0])
            ->assertSessionHas('error', __('A category cannot be moved into itself.'));

        $this->put(route('categories.tree.move', $root->id), ['parent_id' => $grandChild->id, 'position' => 0])
            ->assertSessionHas('error', __('A category cannot be moved into one of its own subcategories.'));

        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_it_allows_moving_a_category_to_an_unrelated_parent_or_the_root(): void
    {
        $first = $this->makeCategory('اول');
        $second = $this->makeCategory('ثاني');

        $this->from(route('categories.tree'))
            ->put(route('categories.tree.move', $first->id), ['parent_id' => $second->id, 'position' => 0])
            ->assertSessionHas('success', __('Category moved successfully'));
        $this->assertSame($second->id, (int) $first->fresh()->parent_id);

        $this->put(route('categories.tree.move', $first->id), ['parent_id' => null, 'position' => 9999])->assertSessionHas('success');
        $this->assertNull($first->fresh()->parent_id);
    }

    public function test_moving_renumbers_siblings_and_clamps_the_position(): void
    {
        $root = $this->makeCategory('جذر');
        $first = $this->makeCategory('اول', $root->id, 0);
        $second = $this->makeCategory('ثاني', $root->id, 1);
        $third = $this->makeCategory('ثالث', $root->id, 2);

        // نقل الثالث الى راس القائمة.
        $this->put(route('categories.tree.move', $third->id), ['parent_id' => $root->id, 'position' => 0]);
        $this->assertSame([$third->id, $first->id, $second->id], $this->siblingsOrder($root->id));

        // موضع خارج المدى يصير آخر القائمة.
        $this->put(route('categories.tree.move', $third->id), ['parent_id' => $root->id, 'position' => 999]);
        $this->assertSame([$first->id, $second->id, $third->id], $this->siblingsOrder($root->id));
    }

    public function test_status_toggle_and_delete(): void
    {
        $root = $this->makeCategory('جذر');
        $child = $this->makeCategory('ابن', $root->id);

        $this->from(route('categories.tree'))->put(route('categories.tree.status', $root->id))->assertSessionHas('success');
        $this->assertSame(CategoryStatus::INACTIVE, $root->fresh()->status);
        $this->put(route('categories.tree.status', $root->id));
        $this->assertSame(CategoryStatus::ACTIVE, $root->fresh()->status);

        $this->delete(route('categories.destroy', $root->id))->assertSessionHas('error', __('Remove the subcategories first.'));
        $this->delete(route('categories.destroy', $child->id))->assertSessionHas('success');
        $this->assertNull(Category::query()->find($child->id));
    }

    public function test_every_write_needs_its_permission(): void
    {
        $root = $this->makeCategory('جذر');
        $other = $this->makeCategory('آخر');

        $this->signIn(['Category View List']);

        $this->put(route('categories.tree.move', $root->id), ['parent_id' => $other->id, 'position' => 0])->assertForbidden();
        $this->put(route('categories.tree.status', $root->id))->assertForbidden();
        $this->delete(route('categories.destroy', $root->id))->assertForbidden();
        $this->get(route('categories.products', $root->id))->assertForbidden();

        $this->assertNull($root->fresh()->parent_id);

        $this->signIn([]);
        $this->get(route('categories.tree'))->assertForbidden();
    }

    public function test_the_category_items_table_lists_and_searches_its_items(): void
    {
        $category = $this->makeCategory('ابواب');
        $other = $this->makeCategory('زجاج');
        $door = new Product(['slug' => 'door-'.Str::random(5), 'sku' => 'D-1', 'base_price' => 5, 'sale_price' => 7, 'pricing_type' => 'simple']);
        $door->save();
        $door->translateOrNew('ar')->name = 'باب خشب';
        $door->save();
        $door->categories()->attach($category->id);
        $glass = new Product(['slug' => 'glass-'.Str::random(5), 'sku' => 'G-1', 'base_price' => 0, 'pricing_type' => 'simple']);
        $glass->save();
        $glass->translateOrNew('ar')->name = 'لوح زجاج';
        $glass->save();
        $glass->categories()->attach($other->id);

        $this->get(route('categories.products', $category->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/CategoryTree/Products')
                ->where('category.name', 'ابواب')
                ->has('table.data', 1)
                ->where('table.data.0.name', 'باب خشب')
                ->where('table.data.0.categories', 'ابواب'));

        $this->get(route('categories.products', [$category->id, 'filter' => ['search' => 'زجاج']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 0));
    }
}
