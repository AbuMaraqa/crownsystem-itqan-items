<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Support\ProductSearch;
use Modules\Items\Support\Search\ItemsSearchIndex;
use Modules\Items\Support\Search\SearchCapabilities;
use Tests\TestCase;

/**
 * البحث عبر فهرس FULLTEXT نفسه. InnoDB لا يرى في الفهرس الا ما اعتُمد، فهذا
 * الاختبار بلا معاملة: يعتمد بياناته ثم يحذفها بنفسه.
 */
class FullTextSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * بلا معاملة حول الاختبار.
     *
     * @var array<int, string|null>
     */
    protected $connectionsToTransact = [];

    /**
     * @var array<int, int>
     */
    private array $productIds = [];

    private ?Category $category = null;

    protected function setUp(): void
    {
        parent::setUp();

        SearchCapabilities::forget();

        if (! SearchCapabilities::hasFullText()) {
            $this->markTestSkipped('No FULLTEXT index on items_products.search_text.');
        }

        $this->category = Category::create(['slug' => 'ft-'.uniqid(), 'code' => 'FT'.substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 6), 'status' => 1]);
        $this->category->translateOrNew('ar')->name = 'قطاعات سبير';
        $this->category->save();
    }

    protected function tearDown(): void
    {
        DB::table('items_products')->whereIn('id', $this->productIds)->delete();
        $this->category?->forceDelete();
        SearchCapabilities::forget();

        parent::tearDown();
    }

    private function product(string $name, string $sku): Product
    {
        $product = Product::create(['slug' => 'ft-'.Str::random(10), 'sku' => $sku, 'base_price' => 0, 'pricing_type' => 'weight', 'is_active' => 1]);
        $product->translateOrNew('ar')->name = $name;
        $product->save();
        $product->categories()->attach($this->category->id);
        $this->productIds[] = (int) $product->id;

        return $product;
    }

    public function test_fulltext_matches_words_in_any_order_with_short_words_and_ranks_them(): void
    {
        // لا يبدأ بـ 3، كي لا تطابقه الكلمة القصيرة "3" أدناه.
        $suffix = random_int(4000, 9999);
        $white = $this->product("ألمنيوم زيكو{$suffix} أبيض", "FTW{$suffix}");
        $profile = $this->product("ثابت 3 مجاري زيكو{$suffix} K4593", "FTP{$suffix}");
        $other = $this->product("ثابت 4 مجاري زيكو{$suffix}", "FTO{$suffix}");
        app(ItemsSearchIndex::class)->flush();

        // كل الكلمات بأي ترتيب، وبلا فرق في الهمزات.
        $this->assertSame([$white->id], ProductSearch::find("ابيض زيكو{$suffix} المنيوم")->ids);

        // الكلمة القصيرة (3) لا يفهرسها FULLTEXT فتُطابَق بـ LIKE مع بقية الكلمات.
        $this->assertSame([$profile->id], ProductSearch::find("زيكو{$suffix} مجاري 3")->ids);

        // كلمة التصنيف تطابق اصنافه، والاقرب (الاسم الاقصر) اولاً.
        $ids = ProductSearch::find("زيكو{$suffix} سبير")->ids;
        $this->assertEqualsCanonicalizing([$white->id, $profile->id, $other->id], $ids);

        // لا صنف فيه كل الكلمات: ما طابق اكثرها اولاً.
        $result = ProductSearch::find("زيكو{$suffix} مجاري فولاذ");
        $this->assertTrue($result->approximate);
        $this->assertEqualsCanonicalizing([$profile->id, $other->id], array_slice($result->ids, 0, 2));
        $this->assertSame($white->id, $result->ids[2]);

        $result = ProductSearch::find("فولاذ زيكو{$suffix} K4593");
        $this->assertTrue($result->approximate);
        $this->assertSame($profile->id, $result->ids[0]);
    }
}
