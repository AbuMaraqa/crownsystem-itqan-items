<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Support\ItemsGrid;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * الصنف الفرعي في جدول اصناف الطلبية لا يكون بنداً مستقلاً: يدخل قطعةً داخل
 * بند صنفه الرئيسي، وداخل الصنف الرئيسي يقتصر البحث على اصنافه الفرعية.
 * نقاط جدول الاصناف (accounting.items-grid.purchase-order.*) وقواعده في Support\ItemsGrid.
 */
class OrderItemsSubItemPlacementTest extends TestCase
{
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
     * @return array{0: Product, 1: Product, 2: Product}
     */
    private function tree(): array
    {
        $main = ConsolidatedTestFactory::product('weight');
        $main->update(['cost_price' => 21]);

        $sub = ConsolidatedTestFactory::product('weight', 6);
        $sub->update(['main_product_id' => $main->id, 'weight_per_meter' => 0.339]);
        $sub->translateOrNew('ar')->name = 'كباس زجاج ثابت';
        $sub->save();

        $otherSub = ConsolidatedTestFactory::product('weight', 4);
        $otherSub->update(['main_product_id' => $main->id]);

        return [$main, $sub, $otherSub];
    }

    /**
     * الصنف كما يدخل جدول اصناف طلبية الشراء، مسعّراً لمورد.
     *
     * @return array<string, mixed>
     */
    private function itemPayload(Product $product, ?int $partyId): array
    {
        return $this->getJson(route('accounting.items-grid.item', array_filter([
            'document' => 'purchase-order',
            'product_id' => $product->id,
            'party_id' => $partyId,
        ])))->assertOk()->json();
    }

    /**
     * @return array<int, int>
     */
    private function pieceOutputs(string $term, int $mainProductId): array
    {
        return collect($this->getJson(route('accounting.items-grid.piece-outputs', [
            'document' => 'purchase-order',
            'q' => $term,
            'main_product_id' => $mainProductId,
        ]))->assertOk()->json())->pluck('id')->all();
    }

    public function test_grid_payload_of_a_sub_item_carries_its_main_item(): void
    {
        [$main, $sub] = $this->tree();
        $supplier = User::factory()->create();

        $payload = $this->itemPayload($sub, $supplier->id);

        $this->assertSame($main->id, $payload['main_product']['id']);
        $this->assertSame('21.0000', $payload['main_product']['unit_price']);
        $this->assertTrue($this->itemPayload($main, $supplier->id)['has_sub_products']);
        $this->assertNull($this->itemPayload($main, $supplier->id)['main_product']);

        // بلا مورد لا سعر تلقائي.
        $this->assertSame('0.0000', $this->itemPayload($sub, null)['main_product']['unit_price']);
    }

    public function test_piece_search_inside_a_main_item_is_limited_to_its_sub_items(): void
    {
        [$main, $sub, $otherSub] = $this->tree();
        $unrelated = ConsolidatedTestFactory::product('weight');

        $results = $this->pieceOutputs('', $main->id);

        $this->assertSame([$sub->id, $otherSub->id], $results);
        $this->assertNotContains($unrelated->id, $results);
    }

    public function test_piece_search_without_a_main_item_tree_offers_weight_items_only(): void
    {
        $weight = ConsolidatedTestFactory::product('weight');
        $simple = ConsolidatedTestFactory::product();
        $mainWithoutSubs = ConsolidatedTestFactory::product('weight');

        // اسم الاختبار مشترك بين الصنفين، فالبحث به يطابقهما معاً.
        $results = collect($this->pieceOutputs('صنف اختبار', $mainWithoutSubs->id));

        $this->assertTrue($results->contains($weight->id));
        $this->assertFalse($results->contains($simple->id));
    }

    public function test_a_new_main_item_cannot_be_saved_without_sub_items(): void
    {
        [$main, $sub] = $this->tree();
        $door = ConsolidatedTestFactory::product();

        // صنف الكمية، والصنف الرئيسي مع صنف فرعي، والبند المحفوظ سابقاً: مقبولة.
        ItemsGrid::ensureMainItemsHaveSubItems([
            ['product_id' => $door->id, 'pieces' => []],
            ['product_id' => $main->id, 'pieces' => [['product_id' => $sub->id, 'piece_count' => 2, 'piece_length' => 6, 'weight_per_meter' => 0.339]]],
            ['id' => 99, 'product_id' => $main->id, 'pieces' => []],
        ]);

        $this->expectException(\DomainException::class);

        // صف صنف فرعي فارغ لم يُختر بعد لا يكفي.
        ItemsGrid::ensureMainItemsHaveSubItems([
            ['product_id' => $main->id, 'product_label' => 'المنيوم سبير بدون دهان', 'pieces' => [['product_id' => null]]],
        ]);
    }

    public function test_a_new_sub_item_cannot_be_saved_without_a_weight(): void
    {
        [$main, $sub] = $this->tree();

        // الصنف الفرعي المحفوظ سابقاً بلا وزن يُترك، كي لا يمنع تعديل طلبية قديمة.
        ItemsGrid::ensureMainItemsHaveSubItems([
            ['id' => 99, 'product_id' => $main->id, 'pieces' => [['id' => 7, 'product_id' => $sub->id, 'piece_count' => 1, 'piece_length' => 0, 'weight_per_meter' => 1.26]]],
        ]);

        try {
            // بلا طول قطعة: 1 × 0 × 1.26 = 0، فلا وزن للصنف الرئيسي ولا سعر.
            ItemsGrid::ensureMainItemsHaveSubItems([
                ['id' => 99, 'product_id' => $main->id, 'product_label' => 'كمبيوتر', 'pieces' => [
                    ['product_id' => $sub->id, 'product_label' => 'ssd', 'piece_count' => 1, 'piece_length' => 0, 'weight_per_meter' => 1.26],
                ]],
            ]);

            $this->fail('A sub-item without a weight was accepted.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('ssd', $exception->getMessage());
            $this->assertStringContainsString('كمبيوتر', $exception->getMessage());
        }
    }

    /**
     * جدول Vue (useItemsGrid) يضع الصنف الفرعي المبحوث داخل بند صنفه الرئيسي من main_product
     * في تفاصيله، بقياسات القطعة منها. هنا ما يرسله السيرفر لذلك.
     */
    public function test_searched_sub_items_carry_what_places_them_inside_one_main_item_line(): void
    {
        [$main, $sub, $otherSub] = $this->tree();
        $door = ConsolidatedTestFactory::product();

        $search = collect($this->getJson(route('accounting.items-grid.products', ['document' => 'purchase-order', 'q' => 'كباس زجاج ثابت']))
            ->assertOk()->json())->keyBy('id');
        $this->assertTrue($search->has($sub->id));
        $this->assertNotNull($search[$sub->id]['main_label'], 'الصنف الفرعي يظهر مع اسم صنفه الرئيسي.');

        $subPayload = $this->itemPayload($sub, null);
        $otherPayload = $this->itemPayload($otherSub, null);

        $this->assertSame($main->id, $subPayload['main_product']['id']);
        $this->assertSame($main->id, $otherPayload['main_product']['id']);
        $this->assertSame('weight', $subPayload['main_product']['pricing_type']);
        $this->assertSame('0.3390', $subPayload['weight_per_meter']);
        $this->assertSame('6.0000', $subPayload['length']);

        $mainPayload = $this->itemPayload($main, null);
        $this->assertNull($mainPayload['main_product']);
        $this->assertSame('weight', $mainPayload['pricing_type']);

        $this->assertNull($this->itemPayload($door, null)['main_product']);
    }
}
