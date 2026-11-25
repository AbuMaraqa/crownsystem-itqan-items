<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\CategoryFinishPrice;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Support\KgPriceResolver;
use Modules\Items\Support\LinePriceContext;
use Modules\Items\Support\PriceResolver;
use Tests\TestCase;

/**
 * سعر الكيلو لسطر مجموعة السعر (التصنيف × اللون) في المستندات: قائمة اسعار الطرف، والا القائمة
 * العامة (اول قائمة مفعّلة)، والا صفر. بلا طرف لا سعر تلقائي.
 */
class KgPriceResolverTest extends TestCase
{
    use RefreshDatabase;

    private CategoryFinish $group;

    private PriceList $cash;

    private PriceList $cheques;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['slug' => 'cat-'.Str::lower(Str::random(6)), 'status' => 1, 'pricing_type' => 'weight']);
        $finish = Finish::create(['name' => 'أبيض', 'is_active' => true]);
        $this->group = CategoryFinish::create(['category_id' => $category->id, 'finish_id' => $finish->id, 'is_active' => true]);
        $this->cash = PriceList::create(['name' => 'نقدي', 'is_active' => true, 'sort_order' => 1]);
        $this->cheques = PriceList::create(['name' => 'شيكات', 'is_active' => true, 'sort_order' => 2]);
        $this->price($this->cash, '20', '15');
    }

    private function price(PriceList $list, string $sell, string $buy, ?PriceList $source = null, ?string $factor = null): void
    {
        CategoryFinishPrice::create([
            'category_finish_id' => $this->group->id, 'price_list_id' => $list->id, 'sell_price' => $sell, 'buy_price' => $buy,
            'source_price_list_id' => $source?->id, 'source_factor' => $factor,
        ]);
    }

    private function party(?PriceList $list): int
    {
        $user = User::factory()->create();
        $user->forceFill(['price_list_id' => $list?->id])->save();

        return (int) $user->id;
    }

    private function resolve(?int $partyId, string $side = PriceResolver::SELL, float $rate = 1.0): float
    {
        return KgPriceResolver::forGroups([$this->group->id], new LinePriceContext($partyId, $rate), $side)[$this->group->id];
    }

    public function test_the_party_price_list_decides(): void
    {
        $this->price($this->cheques, '24', '18');

        $this->assertSame(24.0, $this->resolve($this->party($this->cheques)));
        $this->assertSame(18.0, $this->resolve($this->party($this->cheques), PriceResolver::BUY));
    }

    public function test_without_a_party_list_the_general_list_prices(): void
    {
        $this->assertSame(20.0, $this->resolve($this->party(null)));
        // قائمة الطرف بلا صف لهذه المجموعة: القائمة العامة.
        $this->assertSame(20.0, $this->resolve($this->party($this->cheques)));
    }

    public function test_without_a_party_there_is_no_price(): void
    {
        $this->assertSame(0.0, $this->resolve(null));
    }

    public function test_a_derived_row_is_its_source_times_the_factor(): void
    {
        $this->price($this->cheques, '0', '0', $this->cash, '1.1');

        $this->assertSame(22.0, $this->resolve($this->party($this->cheques)));
    }

    public function test_the_price_follows_the_document_currency(): void
    {
        $this->assertSame(5.0, $this->resolve($this->party(null), PriceResolver::SELL, 4.0));
    }

    /**
     * لون المقطع في المستند يُسعَّر بسعر كيلو مجموعته، لا بصنف آخر.
     */
    public function test_a_colour_variation_is_priced_from_its_price_group_kg_price(): void
    {
        $variation = new Product(['slug' => 'v-'.Str::lower(Str::random(6)), 'base_price' => 3, 'pricing_type' => 'weight', 'length' => 6.5, 'weight_per_meter' => 1.25, 'category_finish_id' => $this->group->id, 'finish_id' => $this->group->finish_id]);
        $variation->save();

        $prices = PriceResolver::forDocumentLines([$variation], new LinePriceContext($this->party($this->cash), 1.0), PriceResolver::SELL, fn () => 99.0);

        $this->assertSame(20.0, $prices[$variation->id]);
    }
}
