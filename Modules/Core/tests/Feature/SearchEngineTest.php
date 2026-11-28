<?php

namespace Modules\Core\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Modules\Accounting\Support\PartySelect;
use Modules\Core\Entities\User;
use Modules\Core\Support\AdminTable;
use Modules\Core\Support\Search\ResilientMeilisearchEngine;
use Modules\Core\Support\Search\SearchIndex;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Support\Search\ItemsSearchIndex;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * محرك البحث (Laravel Scout + Meilisearch): الجداول تُبحث في فهرسها بنص موحَّد، والسجلات
 * المرتبطة في فهارسها، وإن توقف خادم البحث يكمل الحفظ ويعود البحث الى قاعدة البيانات.
 *
 * محرك collection (من Scout) يقوم مقام Meilisearch هنا: يطابق مستند toSearchableArray نفسه.
 */
class SearchEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SearchIndex::reset();
        Cache::forget('search_index.stale');

        parent::tearDown();
    }

    private function useDriver(string $driver, array $meilisearch = []): void
    {
        config(['scout.driver' => $driver]);

        if ($meilisearch !== []) {
            config(['scout.meilisearch' => [...config('scout.meilisearch'), ...$meilisearch]]);
            app()->forgetInstance(\Meilisearch\Client::class);
        }

        app(EngineManager::class)->forgetEngines();
        SearchIndex::reset();
    }

    /**
     * @return list<int>
     */
    private function searchUsers(string $term, array $columns = []): array
    {
        $request = Request::create('/', 'GET', ['filter' => ['search' => $term]]);

        return collect(AdminTable::for(User::query())->search($columns)->sorts('id', 'name')->rows(fn (User $user) => ['id' => $user->id], $request)['data'])
            ->pluck('id')
            ->all();
    }

    public function test_index_text_unifies_arabic_letters_and_joins_codes(): void
    {
        $this->assertSame('موسسه الهدي', SearchIndex::text('مُؤسَّسة الهُدى'));
        $this->assertSame('po 0012 po0012', SearchIndex::text('PO-0012'));
        $this->assertSame('احمد 0599', SearchIndex::text(['أحمد', null, ''], '٠٥٩٩'));
    }

    public function test_a_user_document_holds_its_search_columns_unified(): void
    {
        $user = User::factory()->create(['name' => 'مؤسسة الهدى', 'phone' => '0599-123', 'email' => 'Info@Huda.ps']);

        $this->assertSame([
            'id' => $user->id,
            'name' => 'موسسه الهدي',
            'phone' => '0599 123 0599123',
            'email' => 'info huda ps infohudaps',
        ], $user->toSearchableArray());

        $settings = $user->searchIndexSettings();
        $this->assertSame(['name', 'phone', 'email'], $settings['searchableAttributes']);
        $this->assertSame(['phone', 'email'], $settings['typoTolerance']['disableOnAttributes']);
    }

    public function test_list_search_goes_through_the_index_with_letters_unified(): void
    {
        $this->useDriver('collection');

        $huda = User::factory()->create(['name' => 'مؤسسة الهدى']);
        User::factory()->create(['name' => 'شركة النور']);

        // «مؤسسه» بالهاء و«الهدي» بالياء: لا يطابقها LIKE، ويطابقها الفهرس الموحَّد.
        $this->assertSame([$huda->id], $this->searchUsers('مؤسسه الهدي'));
    }

    public function test_a_related_column_is_searched_in_the_related_index(): void
    {
        $this->useDriver('collection');

        $creator = User::factory()->create(['name' => 'مؤسسة الهدى']);
        $created = User::factory()->create(['name' => 'Sami', 'created_by' => $creator->id]);
        User::factory()->create(['name' => 'Other']);

        $this->assertContains($created->id, $this->searchUsers('مؤسسه', ['createdBy.name']));
        $this->assertNotContains($created->id, $this->searchUsers('Nobody', ['createdBy.name']));
    }

    public function test_party_lookup_uses_the_index_and_keeps_the_role_scope(): void
    {
        $this->useDriver('collection');

        Role::findOrCreate('customer', 'web');
        Role::findOrCreate('supplier', 'web');
        $customer = User::factory()->create(['name' => 'مؤسسة الهدى']);
        $customer->assignRole('customer');
        $supplier = User::factory()->create(['name' => 'مؤسسة الهدى للتوريد']);
        $supplier->assignRole('supplier');

        $this->assertSame([$customer->id], array_column(PartySelect::lookup('customer', 'مؤسسه'), 'value'));
    }

    public function test_a_stopped_search_server_never_breaks_saving_or_searching(): void
    {
        // لا خادم على هذا المنفذ: الاتصال يُرفض فوراً.
        $this->useDriver('meilisearch', ['host' => 'http://127.0.0.1:9', 'connect_timeout' => 0.5, 'timeout' => 1]);
        $this->assertInstanceOf(ResilientMeilisearchEngine::class, SearchIndex::engine());

        $user = User::factory()->create(['name' => 'Sami Haddad']);

        // الحفظ تمّ، والمحرك يُعدّ متوقفاً، وفهرس الجدول قديم يعيده search:sync --stale.
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertFalse(SearchIndex::enabled());
        $this->assertContains(User::class, SearchIndex::stale());

        // البحث يعود الى قاعدة البيانات.
        $this->assertSame([$user->id], $this->searchUsers('Haddad'));
        $this->assertNull(SearchIndex::ids(User::class, 'Haddad'));
    }

    public function test_search_sync_fails_clearly_when_the_server_is_down(): void
    {
        $this->useDriver('meilisearch', ['host' => 'http://127.0.0.1:9', 'connect_timeout' => 0.5, 'timeout' => 1]);

        $this->artisan('search:sync')->assertFailed();
    }

    public function test_search_sync_does_nothing_when_the_engine_is_disabled(): void
    {
        $this->useDriver('null');

        $this->artisan('search:sync')->assertSuccessful();
        $this->assertNull(SearchIndex::ids(User::class, 'anything'));
    }

    public function test_every_searchable_table_has_settings_for_its_index(): void
    {
        $settings = SearchIndex::settings();

        $this->assertContains(Product::class, SearchIndex::models());
        $this->assertContains(\Modules\Accounting\Entities\SalesInvoice::class, SearchIndex::models());

        $invoices = $settings[(new \Modules\Accounting\Entities\SalesInvoice)->searchableAs()];
        $this->assertSame(['reference_no', 'notes'], $invoices['searchableAttributes']);
        $this->assertContains('__soft_deleted', $invoices['filterableAttributes']);
        $this->assertSame(['reference_no'], $invoices['typoTolerance']['disableOnAttributes']);

        $products = $settings[(new Product)->searchableAs()];
        $this->assertSame(['codes', 'names', 'document'], $products['searchableAttributes']);
        $this->assertContains('category_ids', $products['filterableAttributes']);
    }

    public function test_an_item_document_comes_from_the_items_index_with_its_filters(): void
    {
        $category = Category::create(['slug' => 'cat-'.uniqid(), 'code' => '00301', 'status' => 1]);
        $category->translateOrNew('ar')->name = 'ألمنيوم سبير';
        $category->save();

        $main = $this->product('المنيوم سبير أبيض', '00301001', 'SAP-W', $category);
        $sub = $this->product('ثابت 3 مجاري', '00301002', null, null, ['main_product_id' => $main->id]);
        $variation = $this->product('لون', '00301003', null, null, ['parent_id' => $main->id]);

        $document = $main->toSearchableArray();
        $this->assertSame($main->id, $document['id']);
        $this->assertSame('00301001 sap w sapw', $document['codes']);
        $this->assertSame('المنيوم سبير ابيض', $document['names']);
        $this->assertStringContainsString('سبير', $document['document']);
        $this->assertSame([$category->id], $document['category_ids']);
        $this->assertTrue($document['is_active']);

        // الصنف الفرعي يُصفّى بتصنيف صنفه الرئيسي، والمتفرع لا مستند له.
        app()->forgetInstance(ItemsSearchIndex::class);
        $this->assertSame([$category->id], $sub->toSearchableArray()['category_ids']);
        $this->assertSame([], $variation->toSearchableArray());
    }

    private function product(string $name, string $sku, ?string $barcode, ?Category $category, array $attributes = []): Product
    {
        $product = new Product([
            'slug' => Str::slug($sku).'-'.Str::random(6),
            'sku' => $sku,
            'barcode' => $barcode,
            'base_price' => 0,
            'pricing_type' => 'weight',
            'is_active' => 1,
            ...$attributes,
        ]);
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        if ($category) {
            $product->categories()->attach($category->id);
        }

        return $product;
    }
}
