<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Entities\StockCostLayer;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Accounting\tests\Feature\Concerns\PriceGroupFixtures;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * حساب اتقان على (التصنيف × اللون) بلا صنف رئيسي: المجموعة تحمل الكغم وقيمته بطبقات FIFO بالكغم،
 * ولون المقطع يتحرك بالعدد بلا قيمة، وتكلفة كغمه متوسط مجموعته. صنف الوزن بلا قياس قطعة يُخزَّن بالكغم.
 *
 * W45 أبيض: طول 6.5 × وزن متر 1.25 = 8.125 كغم للقطعة.
 */
class WeightVariationStockTest extends TestCase
{
    use PriceGroupFixtures;
    use RefreshDatabase;

    private Warehouse $warehouse;

    private StockLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->warehouse = Warehouse::create(['code' => 'WH1', 'is_active' => true]);
        $this->warehouse->translateOrNew('ar')->name = 'المخزن الرئيسي';
        $this->warehouse->save();
        $this->ledger = app(StockLedgerService::class);
    }

    /**
     * @param  array<string, int>  $key  ['product_id' => ..] او ['category_finish_id' => ..]
     */
    private function receive(array $key, float $quantity, float $weight, float $total): void
    {
        $this->ledger->receive($key + [
            'warehouse_id' => $this->warehouse->id, 'quantity' => $quantity, 'weight' => $weight,
            'unit_cost' => $total / $quantity, 'total_cost' => $total, 'type' => StockMovementType::RECEIPT, 'date' => now()->toDateString(),
        ]);
    }

    public function test_a_price_group_holds_the_kg_with_fifo_value_and_its_colours_only_the_count(): void
    {
        ['white' => $white, 'whiteW45' => $w45] = $this->alpineGroups();

        // 3 كغم بـ 10 ثم 5 كغم بـ 12 على المجموعة، وقطعة W45 بلا قيمة ولو أُعطيت كلفة.
        $this->receive(['category_finish_id' => $white->id], 3, 3, 30);
        $this->receive(['category_finish_id' => $white->id], 5, 5, 60);
        $this->receive(['product_id' => $w45->id], 1, 8.125, 99);

        $group = StockBalance::query()->where('category_finish_id', $white->id)->sole();
        $this->assertSame(8.0, (float) $group->weight);
        $this->assertSame(11.25, $this->ledger->groupAverageCost($white->id));
        $this->assertSame(0.0, $this->ledger->averageCost($w45->id));
        $this->assertSame(0, StockCostLayer::query()->where('product_id', $w45->id)->count());
        $this->assertSame(11.25, $this->ledger->costPerKg($w45));

        // صرف 4 كغم: 3 × 10 + 1 × 12 = 42 (FIFO).
        $issued = $this->ledger->issue([
            'category_finish_id' => $white->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 4, 'weight' => 4,
            'type' => StockMovementType::DELIVERY, 'date' => now()->toDateString(),
        ]);

        $this->assertSame(-42.0, (float) $issued->sum('total_cost'));
        $this->assertSame(4.0, $this->ledger->groupOnHand($white->id));
    }

    public function test_a_group_receipt_is_reversed_exactly(): void
    {
        $white = $this->alpineGroups()['white'];
        $source = User::factory()->create();
        $this->ledger->receive([
            'category_finish_id' => $white->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 8, 'weight' => 8,
            'unit_cost' => 11, 'type' => StockMovementType::RECEIPT, 'date' => now()->toDateString(), 'source' => $source,
        ]);

        $this->ledger->reverse($source, now()->toDateString());

        $this->assertSame(0.0, $this->ledger->groupOnHand($white->id));
        $this->assertSame(0.0, (float) \Modules\Accounting\Entities\ProductCost::query()->where('category_finish_id', $white->id)->value('value'));
        $this->assertSame(0, StockCostLayer::query()->where('category_finish_id', $white->id)->count());
    }

    public function test_a_weight_item_without_a_piece_measure_is_stored_by_kg(): void
    {
        $rubber = new Product(['slug' => 'rubber-'.Str::lower(Str::random(6)), 'base_price' => 0, 'pricing_type' => 'weight']);
        $rubber->save();
        $rubber->translateOrNew('ar')->name = 'مطاط';
        $rubber->save();

        $this->receive(['product_id' => $rubber->id], 50, 50, 200);

        $this->assertTrue($this->ledger->storesByWeight($rubber));
        $this->assertFalse($this->ledger->storesByWeight($this->alpineGroups()['whiteW45']));
        $this->assertSame(4.0, $this->ledger->costPerKg($rubber));
    }
}
