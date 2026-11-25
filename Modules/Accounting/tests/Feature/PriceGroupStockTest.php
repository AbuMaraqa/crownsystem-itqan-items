<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Accounting\Services\StockLevelService;
use Modules\Accounting\tests\Feature\Concerns\PriceGroupFixtures;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * رصيد الكيلو على (التصنيف × اللون) بدل الصنف الرئيسي الوهمي، كما في اتقان: الكيلو وقيمته على
 * المجموعة، وعدد القطع على ألوانها. صفحة الارصدة تُفلتر بالمجموعة وتعرض ألوانها وسطر مجموعها،
 * والصنف الاب (المقطع) يجمع عدد ألوانه.
 *
 * W45 أبيض: 6.5 × 1.25 = 8.125 كغم للقطعة. W60 أبيض: 6.5 × 2 = 13 كغم للقطعة.
 */
class PriceGroupStockTest extends TestCase
{
    use PriceGroupFixtures;
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('Stock View List', 'web'));
        $this->actingAs($user);
        $this->warehouse = Warehouse::create(['code' => 'WH1', 'is_active' => true]);
        $this->warehouse->translateOrNew('ar')->name = 'المخزن الرئيسي';
        $this->warehouse->save();
    }

    /**
     * @param  array<string, int>  $key  ['product_id' => ..] او ['category_finish_id' => ..]
     */
    private function receive(array $key, float $quantity, float $weight, float $total): void
    {
        app(StockLedgerService::class)->receive($key + [
            'warehouse_id' => $this->warehouse->id, 'quantity' => $quantity, 'weight' => $weight,
            'unit_cost' => $total / $quantity, 'total_cost' => $total, 'type' => StockMovementType::RECEIPT, 'date' => now()->toDateString(),
        ]);
    }

    /**
     * @return array{white: \Modules\Items\Entities\CategoryFinish, whiteW45: Product, whiteW60: Product}
     */
    private function stockedWhite(): array
    {
        $groups = $this->alpineGroups();
        // الكيلو على المجموعة: 146.25 كغم بـ 2925. والقطع بلا قيمة: 10 × W45 (81.25 محسوب) و 5 × W60 (65).
        $this->receive(['category_finish_id' => $groups['white']->id], 146.25, 146.25, 2925);
        $this->receive(['product_id' => $groups['whiteW45']->id], 10, 81.25, 0);
        $this->receive(['product_id' => $groups['whiteW60']->id], 5, 65, 0);

        return $groups;
    }

    public function test_a_price_group_balance_is_the_sum_of_its_colour_variations(): void
    {
        $groups = $this->stockedWhite();

        $white = app(StockLevelService::class)->groupLevels([$groups['white']->id])[$groups['white']->id];

        $this->assertSame(15.0, $white['on_hand']);
        $this->assertSame(146.25, $white['weight']);
        $this->assertSame(2925.0, $white['value']);
        $this->assertSame(2, $white['variations']);
    }

    public function test_stock_balances_filter_by_price_group_lists_its_variations_and_a_total(): void
    {
        $groups = $this->stockedWhite();

        $this->get(route('accounting.inventory.stock.index', ['filter' => ['price_group' => $groups['white']->id]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.meta.total', 2)
                ->where('priceGroup.value', $groups['white']->id)
                ->where('groupTotal.on_hand', '15')
                ->where('groupTotal.weight', '146.25'));
    }

    public function test_a_parent_item_sums_the_stock_of_its_colours(): void
    {
        $parent = new Product(['slug' => 'w45-'.Str::lower(Str::random(6)), 'base_price' => 0, 'pricing_type' => 'weight', 'length' => 6.5, 'weight_per_meter' => 1.25]);
        $parent->save();
        $parent->translateOrNew('ar')->name = 'ثابت مجرين W45';
        $parent->save();
        $groups = $this->alpineGroups();
        $groups['whiteW45']->update(['parent_id' => $parent->id]);
        $groups['meshW45']->update(['parent_id' => $parent->id]);
        $this->receive(['product_id' => $groups['whiteW45']->id], 10, 81.25, 0);
        $this->receive(['product_id' => $groups['meshW45']->id], 2, 16.25, 0);

        $levels = app(StockLevelService::class)->parentLevels([$parent->id])[$parent->id];

        $this->assertSame(12.0, $levels['on_hand']);
        $this->assertSame(97.5, $levels['weight']);
        // القيمة على مجموعة السعر لا على ألوان المقطع.
        $this->assertSame(0.0, $levels['value']);
        $this->assertSame(2, $levels['variations']);
    }
}
