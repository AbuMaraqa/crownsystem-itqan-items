<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\CategoryFinishPrice;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * بنية الكتالوج الجديدة: اللون قائمة مستقلة، وسعر الكيلو على (التصنيف × اللون)،
 * والصنف الابن يحمل لونه. التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §5.2-5.4.
 */
class CatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_finish_exists_after_migration(): void
    {
        $finish = Finish::system();

        $this->assertSame('بلا لون', $finish->name);
        $this->assertTrue($finish->is_system);
    }

    public function test_category_finish_is_unique_per_category_and_finish(): void
    {
        $category = $this->category('00448');
        $finish = Finish::query()->create(['name' => 'بدون دهان']);

        CategoryFinish::query()->create(['category_id' => $category->id, 'finish_id' => $finish->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        CategoryFinish::query()->create(['category_id' => $category->id, 'finish_id' => $finish->id]);
    }

    public function test_kg_prices_belong_to_category_finish(): void
    {
        $categoryFinish = CategoryFinish::query()->create([
            'category_id' => $this->category('00448')->id,
            'finish_id' => Finish::query()->create(['name' => 'بدون دهان'])->id,
            'legacy_code' => '00009',
        ]);
        $listId = DB::table(config('items.table_prefix').'price_lists')->insertGetId([
            'same_buy_sell' => false, 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        CategoryFinishPrice::query()->create([
            'category_finish_id' => $categoryFinish->id,
            'price_list_id' => $listId,
            'sell_price' => 11,
            'buy_price' => 10,
        ]);

        $price = $categoryFinish->prices()->first();
        $this->assertSame('11.0000', (string) $price->sell_price);
        $this->assertSame('10.0000', (string) $price->buy_price);
        $this->assertSame($listId, (int) $price->priceList->id);
    }

    public function test_product_has_finish(): void
    {
        $finish = Finish::query()->create(['name' => 'ابيض']);
        $product = Product::query()->create(['slug' => 'w45-white', 'sku' => '00001001', 'base_price' => 0, 'finish_id' => $finish->id]);

        $this->assertTrue($product->fresh()->finish->is($finish));
    }

    private function category(string $code): Category
    {
        return Category::query()->create(['slug' => 'cat-'.$code, 'code' => $code, 'status' => 1]);
    }
}
