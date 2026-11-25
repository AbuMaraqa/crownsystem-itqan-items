<?php

namespace Modules\Accounting\tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Accounting\tests\Feature\Concerns\PriceGroupFixtures;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * بلا صنف رئيسي وهمي: لون المقطع صنف مخزون عادي يحمل عدده ووزنه وقيمته وطبقات تكلفته،
 * وتكلفة الكغم = متوسط القطعة ÷ وزن القطعة. صنف الوزن بلا قياس قطعة يُخزَّن بالكغم.
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

    private function receive(Product $product, float $quantity, float $weight, float $total): void
    {
        $this->ledger->receive([
            'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => $quantity, 'weight' => $weight,
            'unit_cost' => $total / $quantity, 'total_cost' => $total, 'type' => StockMovementType::RECEIPT, 'date' => now()->toDateString(),
        ]);
    }

    private function balance(Product $product): StockBalance
    {
        return StockBalance::query()->where('product_id', $product->id)->where('warehouse_id', $this->warehouse->id)->sole();
    }

    public function test_a_colour_variation_is_received_and_issued_with_its_own_value(): void
    {
        $w45 = $this->alpineGroups()['whiteW45'];

        // 10 قطع × 8.125 = 81.25 كغم بسعر 20 للكغم = 1625.
        $this->receive($w45, 10, 81.25, 1625);

        $this->assertSame(10.0, (float) $this->balance($w45)->quantity);
        $this->assertSame(81.25, (float) $this->balance($w45)->weight);
        $this->assertSame(162.5, $this->ledger->averageCost($w45->id));
        $this->assertSame(20.0, $this->ledger->costPerKg($w45));

        $movement = $this->ledger->issue([
            'product_id' => $w45->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 4,
            'type' => StockMovementType::DELIVERY, 'date' => now()->toDateString(),
        ])->sole();

        $this->assertSame(-650.0, (float) $movement->total_cost);
        $this->assertSame(-32.5, (float) $movement->weight);
    }

    public function test_a_variation_issued_below_zero_keeps_negative_weight_and_value(): void
    {
        Permission::firstOrCreate(['name' => StockLedgerService::NEGATIVE_STOCK_PERMISSION, 'guard_name' => 'web']);
        auth()->user()->givePermissionTo(StockLedgerService::NEGATIVE_STOCK_PERMISSION);
        $w45 = $this->alpineGroups()['whiteW45'];
        $this->receive($w45, 2, 16.25, 325);

        $this->ledger->issue([
            'product_id' => $w45->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 3, 'weight' => 24.375,
            'type' => StockMovementType::DELIVERY, 'date' => now()->toDateString(),
        ]);

        $this->assertSame(-1.0, (float) $this->balance($w45)->quantity);
        $this->assertSame(-8.125, (float) $this->balance($w45)->weight);
    }

    public function test_a_weight_item_without_a_piece_measure_is_stored_by_kg(): void
    {
        $rubber = new Product(['slug' => 'rubber-'.Str::lower(Str::random(6)), 'base_price' => 0, 'pricing_type' => 'weight']);
        $rubber->save();
        $rubber->translateOrNew('ar')->name = 'مطاط';
        $rubber->save();

        $this->receive($rubber, 50, 50, 200);

        $this->assertTrue($this->ledger->storesByWeight($rubber));
        $this->assertFalse($this->ledger->storesByWeight($this->alpineGroups()['whiteW45']));
        $this->assertSame(4.0, $this->ledger->costPerKg($rubber));
    }
}
