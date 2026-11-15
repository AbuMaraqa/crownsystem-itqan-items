<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\Services\StockLevelService;
use Modules\Accounting\Support\StockStatus;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ItemType;
use Tests\TestCase;

/**
 * المخزون في تعريف الصنف على نهج المخزون الدائم: الرصيد يملكه دفتر المخزون وحده،
 * وتنبيه النقص يتبع حدود المخزون نفسها التي تستعملها شاشة الارصدة.
 */
class ProductStockFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'slug' => 'item-'.Str::lower(Str::random(8)),
            'base_price' => 0,
            'pricing_type' => 'simple',
        ], $attributes));
        $product->save();
        $product->translateOrNew('ar')->name = 'صنف '.$product->id;
        $product->save();

        return $product;
    }

    public function test_the_item_form_cannot_overwrite_the_ledger_balance(): void
    {
        $product = $this->product();
        $product->forceFill(['stock_quantity' => 7.5, 'stock_weight' => 12.25])->save();

        // حفظ الصنف (شاشة التعديل او المتغيرات) يمرر قيماً قديمة؛ يجب ان تُهمل.
        $product->update(['stock_quantity' => 999, 'stock_weight' => 1, 'min_limit_1' => 3]);

        $product->refresh();
        $this->assertSame(7.5, (float) $product->stock_quantity);
        $this->assertSame(12.25, (float) $product->stock_weight);
        $this->assertSame(3.0, (float) $product->min_limit_1);
    }

    public function test_low_stock_follows_the_reorder_level_of_goods_only(): void
    {
        $low = $this->product(['min_limit_1' => 10]);
        $low->forceFill(['stock_quantity' => 4])->save();

        $enough = $this->product(['min_limit_1' => 10]);
        $enough->forceFill(['stock_quantity' => 25])->save();

        $noLimit = $this->product();
        $service = $this->product(['min_limit_1' => 10, 'item_type' => ItemType::SERVICE->value]);

        $main = $this->product(['pricing_type' => 'weight', 'min_limit_1' => 10]);
        $this->product(['pricing_type' => 'weight', 'main_product_id' => $main->id]);

        $ids = Product::query()->lowStock()->pluck('id')->all();

        $this->assertContains($low->id, $ids);
        $this->assertNotContains($enough->id, $ids);
        $this->assertNotContains($noLimit->id, $ids);
        $this->assertNotContains($service->id, $ids);
        $this->assertNotContains($main->id, $ids);
    }

    public function test_the_item_stock_summary_is_read_from_the_ledger(): void
    {
        $product = $this->product(['min_limit_1' => 5]);

        $summary = app(StockLevelService::class)->productSummary($product);

        $this->assertSame(0.0, $summary['levels']['on_hand']);
        $this->assertNull($summary['mainLevels']);
        $this->assertSame(StockStatus::OUT, $summary['status']);
        $this->assertSame([], $summary['warehouses']);
    }
}
