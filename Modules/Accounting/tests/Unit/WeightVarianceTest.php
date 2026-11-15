<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Services\PurchaseOrderService;
use Modules\Accounting\Support\ItemsGridState;
use Modules\Accounting\Support\ItemsGridTotals;
use Modules\Accounting\Support\WeightPieces;
use Modules\Core\Entities\User;
use Modules\Core\Settings\GeneralSettings;
use Tests\TestCase;

/**
 * تنبيه فرق الوزن: |الفعلي − المحسوب| ÷ الفعلي × 100 فوق نسبة قابلة للضبط.
 * تنبيه لوني فقط، ولا يمنع الحفظ.
 */
class WeightVarianceTest extends TestCase
{
    use RefreshDatabase;

    private function setTolerance(float $tolerance): void
    {
        $settings = app(GeneralSettings::class);
        $settings->weight_variance_tolerance = $tolerance;
        $settings->save();
    }

    public function test_the_default_tolerance_is_five_percent(): void
    {
        $this->assertSame(5.0, WeightPieces::weightVarianceTolerance());
    }

    public function test_80_against_72_is_eleven_percent_of_the_calculated_weight_and_exceeds(): void
    {
        // المقام هو الوزن المحسوب: 8 ÷ 72.
        $this->assertSame(11.1111, WeightPieces::weightVariancePercent(80, 72));
        $this->assertTrue(WeightPieces::weightVarianceExceeded(80, 72));
    }

    public function test_within_the_tolerance_does_not_exceed(): void
    {
        // 3 ÷ 77 = 3.8961%
        $this->assertSame(3.8961, WeightPieces::weightVariancePercent(80, 77));
        $this->assertFalse(WeightPieces::weightVarianceExceeded(80, 77));
        // عند الحد تماماً لا يتجاوز.
        $this->assertSame(5.0, WeightPieces::weightVariancePercent(105, 100));
        $this->assertFalse(WeightPieces::weightVarianceExceeded(105, 100));
        $this->assertSame(0.0, WeightPieces::weightVariancePercent(72, 72));
        $this->assertFalse(WeightPieces::weightVarianceExceeded(72, 72));
    }

    public function test_the_difference_counts_in_both_directions(): void
    {
        // 10 ÷ 90 = 11.1111%، والوزن الفعلي هنا اقل من المحسوب.
        $this->assertSame(11.1111, WeightPieces::weightVariancePercent(80, 90));
        $this->assertTrue(WeightPieces::weightVarianceExceeded(80, 90));
    }

    public function test_null_when_the_calculated_weight_is_not_positive(): void
    {
        foreach ([0, '0', null, '', -5, 'abc'] as $calculated) {
            $this->assertNull(WeightPieces::weightVariancePercent(72, $calculated));
            $this->assertFalse(WeightPieces::weightVarianceExceeded(72, $calculated));
        }
    }

    public function test_an_actual_weight_of_zero_is_a_full_variance_for_the_formula_only(): void
    {
        // المعادلة وحدها تعطي 100%؛ سطر الجدول بلا وزن فعلي لا يُنبَّه عليه (الاختبار ادناه).
        $this->assertSame(100.0, WeightPieces::weightVariancePercent(0, 72));
    }

    public function test_the_tolerance_is_configurable(): void
    {
        $this->setTolerance(15);

        $this->assertFalse(WeightPieces::weightVarianceExceeded(80, 72));
        $this->assertTrue(WeightPieces::weightVarianceExceeded(80, 60));

        $this->setTolerance(2);

        $this->assertTrue(WeightPieces::weightVarianceExceeded(80, 77));
    }

    /**
     * @return array{0: \Modules\Accounting\Entities\PurchaseOrder, 1: \Modules\Items\Entities\Product}
     */
    private function savedOrder(mixed $actualWeight): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $main = ConsolidatedTestFactory::product('weight');
        $sub = ConsolidatedTestFactory::product('weight', 6);
        $sub->update(['main_product_id' => $main->id, 'weight_per_meter' => 1.2]);

        $order = app(PurchaseOrderService::class)->create([
            'client_id' => $user->id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $main->id,
                'quantity' => 1,
                'actual_weight' => $actualWeight,
                // 10 قطع × 6 م × 1.2 كغم/م = 72 كغم
                'pieces' => [['product_id' => $sub->id, 'piece_count' => 10, 'piece_length' => 6, 'weight_per_meter' => 1.2]],
            ]],
        ]);

        return [$order, $main];
    }

    public function test_the_warning_does_not_block_saving(): void
    {
        [$order] = $this->savedOrder(80);

        $item = $order->items->first();

        // الفرق 10% فوق النسبة، والسطر محفوظ بوزنيه كما أُدخلا.
        $this->assertTrue(WeightPieces::weightVarianceExceeded($item->actual_weight, $item->total_weight));
        $this->assertSame(80.0, (float) $item->actual_weight);
        $this->assertSame(72.0, (float) $item->total_weight);

        $this->assertTrue(ItemsGridState::rows($order->items)[0]['weight_variance_exceeded']);
    }

    public function test_the_warning_is_also_not_blocking_on_update(): void
    {
        [$order, $main] = $this->savedOrder(73);

        $this->assertFalse(ItemsGridState::rows($order->items)[0]['weight_variance_exceeded']);

        $item = $order->items->first();
        $piece = $item->pieces->first();

        $updated = app(PurchaseOrderService::class)->update($order, [
            'client_id' => $order->client_id,
            'order_date' => now()->toDateString(),
            'items' => [[
                'id' => $item->id,
                'product_id' => $main->id,
                'quantity' => 1,
                'actual_weight' => 200,
                'pieces' => [[
                    'id' => $piece->id,
                    'product_id' => $piece->product_id,
                    'piece_count' => 10,
                    'piece_length' => 6,
                    'weight_per_meter' => 1.2,
                ]],
            ]],
        ]);

        $this->assertSame(200.0, (float) $updated->items->first()->actual_weight);
        $this->assertTrue(ItemsGridState::rows($updated->items)[0]['weight_variance_exceeded']);
    }

    public function test_a_line_without_calculated_weight_or_actual_weight_is_not_flagged(): void
    {
        $this->actingAs(User::factory()->create());

        $order = ConsolidatedTestFactory::purchaseOrder();
        $old = ConsolidatedTestFactory::product('weight');
        $simple = ConsolidatedTestFactory::product('simple');

        // سطر قديم بلا قطع، وسطر وزن بلا وزن فعلي، وسطر كمية.
        $order->items()->create(['product_id' => $old->id, 'description' => 'قديم', 'quantity' => 1, 'total_weight' => 0, 'actual_weight' => 500]);
        $order->items()->create(['product_id' => $old->id, 'description' => 'بلا فعلي', 'quantity' => 1, 'total_weight' => 72]);
        $order->items()->create(['product_id' => $simple->id, 'description' => 'كمية', 'quantity' => 1, 'actual_weight' => 50, 'total_weight' => 10]);

        $rows = ItemsGridState::rows($order->refresh()->load(['items.product', 'items.pieces'])->items);

        $this->assertSame([false, false, false], array_column($rows, 'weight_variance_exceeded'));
    }

    /**
     * جدول Vue يعرض تنبيهات السطر من نقطة المجاميع (ItemsGridTotals) بالنسبة المضبوطة.
     */
    public function test_the_grid_receives_the_tolerance_and_shows_the_warning(): void
    {
        $this->setTolerance(7.5);

        $main = ConsolidatedTestFactory::product('weight');
        $piece = ConsolidatedTestFactory::product('weight', 6);
        $piece->update(['main_product_id' => $main->id, 'weight_per_meter' => 1.2]);

        // 10 × 6 × 1.2 = 72 كغم محسوب.
        $line = ['product_id' => $main->id, 'unit_price' => 5, 'pieces' => [['product_id' => $piece->id, 'piece_count' => 10]]];
        $types = fn (float $actual): array => array_column(ItemsGridTotals::compute([$line + ['actual_weight' => $actual]])['lines'][0]['notices'], 'type');

        // 5 ÷ 72 ≈ 6.9% داخل 7.5%، و6 ÷ 72 ≈ 8.3% فوقها.
        $this->assertSame([], $types(77));
        $this->assertSame(['weight_variance'], $types(78));

        $grid = file_get_contents(module_path('Accounting', 'resources/js/Pages/parts/items-grid/useItemsGrid.ts'));
        $this->assertStringContainsString('notices.value = Object.fromEntries(result.lines.map((line) => [line.key, line.notices]));', $grid);
    }
}
