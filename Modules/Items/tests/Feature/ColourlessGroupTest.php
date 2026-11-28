<?php

namespace Modules\Items\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\Product;
use Modules\Items\Support\ColourlessGroup;
use Tests\TestCase;

/**
 * مقطع بالوزن لم يُضف له لون يدخل مجموعة «تصنيفه — بلا لون»، فيظهر في الطلبيات كالمقاطع الملوّنة:
 * سطر المجموعة وتحته المقطع صنفاً فرعياً.
 */
class ColourlessGroupTest extends TestCase
{
    use RefreshDatabase;

    private Category $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->brand = $this->category('الوبين', 'weight');
    }

    public function test_a_profile_without_a_colour_joins_the_colourless_group_of_its_category(): void
    {
        $profile = $this->product($this->brand, length: 6, weightPerMeter: 0.156);

        $report = ColourlessGroup::assign();

        $group = CategoryFinish::query()->where('category_id', $this->brand->id)->where('finish_id', Finish::system()->id)->sole();
        $profile->refresh();
        $this->assertSame($group->id, (int) $profile->category_finish_id);
        $this->assertSame(Finish::system()->id, (int) $profile->finish_id);
        $this->assertSame(1, $report['assigned']);
        $this->assertTrue(app(StockLedgerService::class)->isGroupPiece(\Modules\Accounting\Support\StockKey::product($profile->id)));
    }

    public function test_profiles_with_colours_items_without_a_measure_and_quantity_categories_are_left(): void
    {
        $withColour = $this->product($this->brand, length: 6, weightPerMeter: 1);
        $white = CategoryFinish::create(['category_id' => $this->brand->id, 'finish_id' => Finish::create(['name' => 'أبيض', 'is_active' => true])->id, 'is_active' => true]);
        $this->product($this->brand, length: 6, weightPerMeter: 1, parentId: $withColour->id)->forceFill(['category_finish_id' => $white->id])->save();
        $rubber = $this->product($this->brand, length: 0, weightPerMeter: 0);
        $simple = $this->product($this->category('براغي', 'simple'), length: 6, weightPerMeter: 1);

        $report = ColourlessGroup::assign();

        $this->assertSame(0, $report['assigned']);
        $this->assertNull($withColour->refresh()->category_finish_id);
        $this->assertNull($rubber->refresh()->category_finish_id);
        $this->assertNull($simple->refresh()->category_finish_id);
    }

    public function test_a_profile_with_stock_is_reported_not_moved(): void
    {
        $profile = $this->product($this->brand, length: 6, weightPerMeter: 1);
        $warehouse = Warehouse::create(['code' => 'WH1', 'is_active' => true]);
        app(StockLedgerService::class)->receive([
            'product_id' => $profile->id, 'warehouse_id' => $warehouse->id, 'quantity' => 2, 'weight' => 12,
            'unit_cost' => 5, 'type' => StockMovementType::OPENING, 'date' => now()->toDateString(),
        ]);

        $report = ColourlessGroup::assign();

        $this->assertSame(0, $report['assigned']);
        $this->assertSame([$profile->id], $report['with_stock']);
        $this->assertNull($profile->refresh()->category_finish_id);
    }

    public function test_the_command_reports_first_and_assigns_with_execute(): void
    {
        $profile = $this->product($this->brand, length: 6, weightPerMeter: 1);

        $this->artisan('items:colourless-groups')->assertSuccessful();
        $this->assertNull($profile->refresh()->category_finish_id);

        $this->artisan('items:colourless-groups', ['--execute' => true])->assertSuccessful();
        $this->assertNotNull($profile->refresh()->category_finish_id);
    }

    private function category(string $name, string $pricingType): Category
    {
        $category = Category::create(['slug' => 'c-'.Str::lower(Str::random(8)), 'status' => 1, 'pricing_type' => $pricingType]);
        $category->translateOrNew('ar')->name = $name;
        $category->save();

        return $category;
    }

    private function product(Category $category, float $length, float $weightPerMeter, ?int $parentId = null): Product
    {
        $product = new Product([
            'slug' => 'p-'.Str::lower(Str::random(10)),
            'sku' => 'SKU-'.Str::random(8),
            'base_price' => 0,
            'pricing_type' => 'weight',
            'length' => $length,
            'weight_per_meter' => $weightPerMeter,
        ]);
        $product->parent_id = $parentId;
        $product->save();
        $product->translateOrNew('ar')->name = 'مقطع';
        $product->save();
        $product->categories()->attach($category->id);

        return $product;
    }
}
