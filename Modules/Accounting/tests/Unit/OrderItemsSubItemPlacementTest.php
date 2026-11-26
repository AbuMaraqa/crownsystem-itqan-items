<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Support\ItemsGrid;
use Modules\Accounting\tests\Feature\Concerns\PriceGroupFixtures;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * القطع في جدول اصناف الطلبية لا تكون بنداً مستقلاً: تدخل داخل سطر مجموعة سعرها (التصنيف × اللون)،
 * وسطر المجموعة لا يُحفظ بلا قطع لها وزن.
 * نقاط جدول الاصناف (accounting.items-grid.purchase-order.*) وقواعده في Support\ItemsGrid.
 */
class OrderItemsSubItemPlacementTest extends TestCase
{
    use PriceGroupFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('Purchase Order Create', 'web'));
        $this->actingAs($user);
    }

    /**
     * @return array<int, int>
     */
    private function pieceOutputs(string $term): array
    {
        return collect($this->getJson(route('accounting.items-grid.piece-outputs', [
            'document' => 'purchase-order',
            'q' => $term,
        ]))->assertOk()->json())->pluck('id')->all();
    }

    public function test_piece_search_without_a_price_group_offers_weight_items_only(): void
    {
        $weight = ConsolidatedTestFactory::product('weight');
        $simple = ConsolidatedTestFactory::product();

        // اسم الاختبار مشترك بين الصنفين، فالبحث به يطابقهما معاً.
        $results = collect($this->pieceOutputs('صنف اختبار'));

        $this->assertTrue($results->contains($weight->id));
        $this->assertFalse($results->contains($simple->id));
    }

    public function test_a_price_group_line_cannot_be_saved_without_pieces(): void
    {
        ['white' => $white, 'whiteW45' => $w45] = $this->alpineGroups();
        $door = ConsolidatedTestFactory::product();

        // صنف الكمية، وسطر المجموعة بقطعة من ألوانها: مقبولان.
        ItemsGrid::ensureWeightLines([
            ['product_id' => $door->id, 'pieces' => []],
            $this->groupLine($white, [[$w45, 2]], 16),
        ]);

        $this->expectException(\DomainException::class);

        // صف قطعة فارغ لم يُختر بعد لا يكفي.
        ItemsGrid::ensureWeightLines([
            array_replace($this->groupLine($white, []), ['pieces' => [['product_id' => null]]]),
        ]);
    }

    public function test_a_piece_of_a_price_group_line_cannot_be_saved_without_a_weight(): void
    {
        ['white' => $white, 'whiteW45' => $w45] = $this->alpineGroups();
        $line = $this->groupLine($white, [[$w45, 1]]);
        // بلا طول قطعة: 1 × 0 × 1.25 = 0، فلا وزن لسطر المجموعة ولا سعر.
        $line['pieces'][0] = array_replace($line['pieces'][0], ['product_label' => 'ssd', 'piece_length' => 0]);

        try {
            ItemsGrid::ensureWeightLines([$line]);

            $this->fail('A piece without a weight was accepted.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('ssd', $exception->getMessage());
            $this->assertStringContainsString('الوبين', $exception->getMessage());
        }
    }
}
