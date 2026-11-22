<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Itqan\LegacyLinks;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\CategoryFinishPrice;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductPrice;
use Modules\Items\Services\Itqan\ItqanPricesStage;
use Tests\TestCase;

/**
 * سعر إتقان على عقدة تصنيف الالمنيوم هو سعر الكيلو لـ (التصنيف × اللون)؛ وسعره على
 * الورقة سعر الصنف. التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §6.2.
 */
class ItqanPricesRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_node_price_routes_to_kg_price(): void
    {
        $this->assertSame(['target' => 'kg', 'id' => 5], ItqanPricesStage::route(['ItemId' => '00009 '], ['00009' => 5], ['00009' => 9]));
    }

    public function test_leaf_price_routes_to_product(): void
    {
        $this->assertSame(['target' => 'product', 'id' => 7], ItqanPricesStage::route(['ItemId' => '00020001'], ['00009' => 5], ['00020001' => 7]));
    }

    public function test_unlinked_price_is_missing(): void
    {
        $this->assertSame(['target' => 'missing', 'id' => null], ItqanPricesStage::route(['ItemId' => '99999'], [], []));
    }

    public function test_stage_writes_kg_prices_and_product_prices(): void
    {
        $listId = DB::table(config('items.table_prefix').'price_lists')->insertGetId([
            'same_buy_sell' => false, 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $group = CategoryFinish::query()->create([
            'category_id' => Category::query()->create(['slug' => 'cat-00448', 'code' => '00448', 'status' => 1])->id,
            'finish_id' => Finish::query()->create(['name' => 'بدون دهان'])->id,
            'legacy_code' => '00009',
        ]);
        $product = Product::query()->create(['slug' => 'itqan-p-x', 'sku' => '004480001', 'base_price' => 0]);

        $links = app(LegacyLinks::class);
        $links->remember('items', CategoryFinish::class, ['00009' => $group->id]);
        $links->remember('items', Product::class, ['00020001' => $product->id, '00020002' => $product->id]);

        $report = app(ItqanPricesStage::class)->importPrices([
            $this->price('1', '00009', 11, 10),
            $this->price('1', '00020001', 5, 4),
            $this->price('1', '00020002', 6, 4),
            $this->price('1', '77777', 1, 1),
        ], ['1' => $listId], false, fn () => null);

        $kg = CategoryFinishPrice::query()->sole();
        $this->assertSame('11.0000', (string) $kg->sell_price);
        $this->assertSame('10.0000', (string) $kg->buy_price);
        $this->assertSame($listId, (int) $kg->price_list_id);

        $this->assertSame('6.0000', (string) ProductPrice::query()->where('product_id', $product->id)->sole()->sell_price);
        $this->assertSame(1, $report['kg_prices_written']);
        $this->assertSame(1, $report['prices_written']);
        $this->assertSame(1, $report['prices_merged_into_parent']);
        $this->assertSame(1, $report['prices_for_missing_items']);
    }

    /**
     * @return array<string, mixed>
     */
    private function price(string $list, string $item, float $sell, float $buy): array
    {
        return ['PriceListId' => $list, 'ItemId' => $item, 'SellPrice' => $sell, 'BuyPrice' => $buy, 'itemunit' => '', 'Equation' => ''];
    }
}
