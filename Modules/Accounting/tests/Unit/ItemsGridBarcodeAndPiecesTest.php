<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\Support\ItemsGrid;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;
use Modules\Items\Enums\ItemType;
use Modules\Items\Support\LinePriceContext;
use Modules\Items\Support\PriceResolver;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * دوال الجدول: الباركود يعيد كل المطابقات بكمية عبوتها، ومقاطع السطر تُطابق بالاسم
 * تحت الصنف الرئيسي الجديد وما لم يطابق يُبلَّغ عنه.
 */
class ItemsGridBarcodeAndPiecesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * جدول مستند بلا طرف (كما في نقاط accounting.items-grid.*).
     */
    private function grid(): ItemsGrid
    {
        return ItemsGrid::for(PriceResolver::BUY, LinePriceContext::for(null));
    }

    private function product(string $name, ?string $barcode = null, ?Product $main = null, array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'slug' => 'g-'.Str::random(8),
            'sku' => 'G-'.Str::random(8),
            'barcode' => $barcode,
            'base_price' => 1,
            'pricing_type' => $main ? 'weight' : 'simple',
            'main_product_id' => $main?->id,
        ], $attributes));
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    public function test_one_match_carries_the_pack_quantity_of_its_barcode(): void
    {
        $item = $this->product('مقبض');
        ProductBarcode::query()->create(['product_id' => $item->id, 'barcode' => 'BOX12', 'pack_quantity' => 12, 'is_prefix' => false, 'sort_order' => 1]);

        $result = ItemsGrid::resolveBarcode('BOX12');

        $this->assertCount(1, $result['matches']);
        $this->assertSame((int) $item->id, $result['matches'][0]['id']);
        $this->assertSame(12.0, (float) $result['matches'][0]['pack_quantity']);
        $this->assertNull($result['add_url']);
    }

    public function test_a_barcode_repeated_across_colors_returns_every_item_with_its_main(): void
    {
        $iron = $this->product('لون ايرون', null, null, ['pricing_type' => 'weight']);
        $white = $this->product('لون ابيض', null, null, ['pricing_type' => 'weight']);
        $this->product('مقطع', 'SAME', $iron);
        $this->product('مقطع', 'SAME', $white);

        $result = ItemsGrid::resolveBarcode('SAME');

        $this->assertCount(2, $result['matches']);
        $this->assertStringContainsString('لون ايرون', $result['matches'][0]['main_label']);
        $this->assertStringContainsString('لون ابيض', $result['matches'][1]['main_label']);
    }

    public function test_the_same_item_matched_twice_is_one_match(): void
    {
        $item = $this->product('مقبض', 'DUP');
        ProductBarcode::query()->create(['product_id' => $item->id, 'barcode' => 'DUP', 'pack_quantity' => 1, 'is_prefix' => false, 'sort_order' => 1]);

        $this->assertCount(1, ItemsGrid::resolveBarcode('DUP')['matches']);
    }

    public function test_services_are_not_added_from_the_barcode_field(): void
    {
        $this->product('نقل', 'SRV', null, ['item_type' => ItemType::SERVICE->value]);

        $this->assertSame([], ItemsGrid::resolveBarcode('SRV')['matches']);
    }

    public function test_no_match_offers_the_add_link_only_when_permitted(): void
    {
        $this->assertNull(ItemsGrid::resolveBarcode('NOPE')['add_url']);

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('Product Create', 'web'));
        $this->actingAs($user);

        $result = ItemsGrid::resolveBarcode('NOPE');

        $this->assertSame([], $result['matches']);
        $this->assertSame(route('products.add'), $result['add_url']);
    }

    public function test_pieces_match_by_name_and_the_unmatched_are_listed(): void
    {
        $oldMain = $this->product('القديم', null, null, ['pricing_type' => 'weight']);
        $newMain = $this->product('الجديد', null, null, ['pricing_type' => 'weight']);
        $kept = $this->product('ثابت', null, $oldMain);
        $missing = $this->product('حلق', null, $oldMain);
        $target = $this->product('ثابت', null, $newMain, ['weight_per_meter' => 1.5, 'length' => 6]);

        $result = $this->grid()->matchPiecesByName($newMain->id, [$kept->id, $missing->id]);

        $this->assertCount(1, $result['matched']);
        $this->assertSame((int) $kept->id, $result['matched'][0]['source_id']);
        $this->assertSame((int) $target->id, $result['matched'][0]['product']['id']);
        $this->assertSame(['حلق'], $result['unmatched']);
    }

    public function test_a_name_repeated_under_the_new_main_is_used_once_per_piece(): void
    {
        $oldMain = $this->product('القديم', null, null, ['pricing_type' => 'weight']);
        $newMain = $this->product('الجديد', null, null, ['pricing_type' => 'weight']);
        $first = $this->product('ثابت', null, $oldMain);
        $second = $this->product('ثابت', null, $oldMain);
        $third = $this->product('ثابت', null, $oldMain);
        $this->product('ثابت', null, $newMain);
        $this->product('ثابت', null, $newMain);

        $result = $this->grid()->matchPiecesByName($newMain->id, [$first->id, $second->id, $third->id]);

        $this->assertCount(2, $result['matched']);
        $this->assertNotSame($result['matched'][0]['product']['id'], $result['matched'][1]['product']['id']);
        $this->assertSame(['ثابت'], $result['unmatched']);
    }

    /**
     * جدول Vue: تغيير الصنف الرئيسي يعرض الخيارات (ابقاء، مطابقة بالاسم، مسح)، وEnter في حقل
     * الباركود يقرأه.
     */
    public function test_the_grid_offers_the_choices_and_can_clear(): void
    {
        $grid = file_get_contents(module_path('Accounting', 'resources/js/Pages/parts/items-grid/useItemsGrid.ts'));
        $barcode = file_get_contents(module_path('Accounting', 'resources/js/Pages/parts/items-grid/BarcodeEntry.vue'));

        $this->assertStringContainsString('offerPieces(row, previousPieces);', $grid);
        $this->assertStringContainsString('row.pieces = [];', $grid);
        $this->assertStringContainsString('@keydown.enter.prevent.stop="grid.scanBarcode(', $barcode);
    }
}
