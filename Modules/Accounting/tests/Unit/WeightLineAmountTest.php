<?php

namespace Modules\Accounting\tests\Unit;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\Support\ItemsGridState;
use Modules\Accounting\Support\ItemsGridTotals;
use Modules\Accounting\Support\WeightPieces;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * مبلغ سطر الوزن = الوزن الفعلي × سعر الكغم، وصفر اذا كان الفعلي فارغاً ولو حسبت القطع
 * وزناً (قرار صاحب النظام، كما في النظام القديم). الوزن المحسوب يبقى للعرض والمخزون.
 */
class WeightLineAmountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_pricing_weight_is_the_actual_weight_or_zero(): void
    {
        $pieces = [['total_weight' => 72.0]];

        $this->assertSame(80.0, WeightPieces::pricingWeight($pieces, 80));
        $this->assertSame(0.0, WeightPieces::pricingWeight($pieces, null));
        $this->assertSame(0.0, WeightPieces::pricingWeight($pieces, ''));
        $this->assertSame(0.0, WeightPieces::pricingWeight($pieces, 0));
    }

    public function test_line_keeps_the_calculated_weight_when_the_actual_is_empty(): void
    {
        [$main, $piece] = $this->mainWithPiece();

        $line = WeightPieces::line(['pieces' => [['product_id' => $piece->id, 'piece_count' => 10]]], $main);

        $this->assertSame(72.0, $line['total_weight']);
        $this->assertNull($line['actual_weight']);
        $this->assertSame(0.0, $line['pricing_weight']);
        $this->assertSame(72.0, $line['pieces'][0]['total_weight']);
    }

    public function test_line_prices_by_the_actual_weight_when_entered(): void
    {
        [$main, $piece] = $this->mainWithPiece();

        $line = WeightPieces::line(['actual_weight' => '80', 'pieces' => [['product_id' => $piece->id, 'piece_count' => 10]]], $main);

        $this->assertSame(80.0, $line['pricing_weight']);
        $this->assertSame(72.0, $line['total_weight']);
    }

    public function test_a_weight_line_without_pieces_is_priced_by_its_entered_quantity(): void
    {
        [$main] = $this->mainWithPiece();

        $this->assertNull(WeightPieces::line(['quantity' => 90], $main)['pricing_weight']);
        $this->assertSame(50.0, WeightPieces::line(['quantity' => 90, 'actual_weight' => 50], $main)['pricing_weight']);
    }

    public function test_a_reopened_zero_line_passes_the_quantity_rule(): void
    {
        [$main, $piece] = $this->mainWithPiece();
        $item = new \Modules\Accounting\Entities\SalesInvoiceItem(['product_id' => $main->id, 'quantity' => 0, 'total_weight' => 72, 'unit_price' => 5]);
        $item->setRelation('product', $main);
        $item->setRelation('pieces', collect([new \Modules\Accounting\Entities\SalesInvoiceItemPiece(['product_id' => $piece->id, 'piece_count' => 10, 'total_weight' => 72])]));

        $rows = ItemsGridState::rows([$item], quantityIsWeight: true);

        $this->assertGreaterThan(0, (float) $rows[0]['quantity']);
        $this->assertNull($rows[0]['actual_weight']);
    }

    public function test_the_page_totals_use_the_same_rule_and_keep_the_return_factor(): void
    {
        [$main, $piece] = $this->mainWithPiece();
        // 10 قطع × 6 م × 1.2 = 72 كغم نظري.
        $line = ['product_id' => $main->id, 'unit_price' => 5, 'pieces' => [['product_id' => $piece->id, 'piece_count' => 10]]];
        $totals = fn (array $item): array => ItemsGridTotals::compute([$item])['lines'][0];

        $this->assertSame(0.0, (float) $totals($line)['line_total']);
        $this->assertSame(400.0, (float) $totals($line + ['actual_weight' => 80])['line_total']);
        // مرتجع: كغم مفوترة لكل كغم نظري، وسطره بلا وزن فعلي.
        $this->assertSame(360.0, (float) $totals($line + ['weight_factor' => 1])['line_total']);

        // تنبيه «الوزن الفعلي فارغ» لسطر الوزن بلا فعلي، لا للمرتجع.
        $this->assertSame(['actual_weight_empty'], array_column($totals($line)['notices'], 'type'));
        $this->assertSame([], $totals($line + ['weight_factor' => 1])['notices']);
    }

    public function test_the_grid_javascript_follows_the_rule_and_shows_the_notice(): void
    {
        $grid = file_get_contents(module_path('Accounting', 'resources/js/Pages/parts/items-grid/useItemsGrid.ts'));

        $this->assertStringContainsString('weight_factor: item.weight_factor ?? 0,', $grid);
        $this->assertStringContainsString('quantity = actual > 0 ? actual : hasPieces ? 0 : quantity;', $grid);
        $this->assertStringContainsString('if (!(actual > 0) && num(row.weight_factor) > 0) quantity = round4(totalWeight * num(row.weight_factor));', $grid);
        $this->assertSame('الوزن الفعلي فارغ', json_decode(file_get_contents(module_path('Accounting', 'Lang/ar.json')), true)['Actual weight is empty']);
    }

    /**
     * @return array{0: Product, 1: Product}
     */
    private function mainWithPiece(): array
    {
        $main = new Product(['slug' => 'main-'.Str::random(8), 'base_price' => 0, 'pricing_type' => 'weight']);
        $main->save();
        $piece = new Product(['slug' => 'piece-'.Str::random(8), 'base_price' => 0, 'pricing_type' => 'weight', 'main_product_id' => $main->id, 'length' => 6, 'weight_per_meter' => 1.2]);
        $piece->save();

        return [$main, $piece];
    }
}
