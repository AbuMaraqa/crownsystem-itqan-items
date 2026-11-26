<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\Support\ItemsGrid;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;
use Modules\Items\Enums\ItemType;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * دوال الجدول: الباركود يعيد كل المطابقات بكمية عبوتها، وEnter في حقله يقرأه.
 */
class ItemsGridBarcodeAndPiecesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function product(string $name, ?string $barcode = null, array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'slug' => 'g-'.Str::random(8),
            'sku' => 'G-'.Str::random(8),
            'barcode' => $barcode,
            'base_price' => 1,
            'pricing_type' => 'simple',
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

    public function test_a_barcode_repeated_across_colors_returns_every_item(): void
    {
        $iron = $this->product('مقطع - ايرون', 'SAME', ['pricing_type' => 'weight']);
        $white = $this->product('مقطع - ابيض', 'SAME', ['pricing_type' => 'weight']);

        $result = ItemsGrid::resolveBarcode('SAME');

        $this->assertSame([(int) $iron->id, (int) $white->id], array_column($result['matches'], 'id'));
    }

    public function test_the_same_item_matched_twice_is_one_match(): void
    {
        $item = $this->product('مقبض', 'DUP');
        ProductBarcode::query()->create(['product_id' => $item->id, 'barcode' => 'DUP', 'pack_quantity' => 1, 'is_prefix' => false, 'sort_order' => 1]);

        $this->assertCount(1, ItemsGrid::resolveBarcode('DUP')['matches']);
    }

    public function test_services_are_not_added_from_the_barcode_field(): void
    {
        $this->product('نقل', 'SRV', ['item_type' => ItemType::SERVICE->value]);

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

    /**
     * جدول Vue: Enter في حقل الباركود يقرأه.
     */
    public function test_enter_in_the_barcode_field_reads_it(): void
    {
        $barcode = file_get_contents(module_path('Accounting', 'resources/js/Pages/parts/items-grid/BarcodeEntry.vue'));

        $this->assertStringContainsString('@keydown.enter.prevent.stop="grid.scanBarcode(', $barcode);
    }
}
