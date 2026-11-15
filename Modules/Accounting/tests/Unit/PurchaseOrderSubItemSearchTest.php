<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Accounting\Support\PurchaseOrderMainItems;
use Modules\Items\Entities\Category;
use Tests\TestCase;

/**
 * الصنف الرئيسي واصنافه الفرعية في الطلبية التجميعية، كما في شاشة النظام القديم:
 * بند الوزن رأسه الصنف الرئيسي وتحته قطعه، والبحث بالصنف الفرعي يُظهر رئيسيه.
 */
class PurchaseOrderSubItemSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * «المنيوم سبير F40 SAPIR» وبداخله «كباس زجاج ثابت» 60 قطعة × 6م × 0.339 = 122.04.
     */
    private function sapirLine(): PurchaseOrderItem
    {
        $main = ConsolidatedTestFactory::product('weight');
        $main->update(['sku' => '00088']);

        $line = ConsolidatedTestFactory::purchaseOrder()->items()->create([
            'product_id' => $main->id,
            'description' => 'المنيوم سبير F40 SAPIR',
            'barcode' => 'GJH75398',
            'quantity' => 1,
            'unit_price' => 21,
            'line_total' => 2562.84,
            'total_weight' => 122.04,
            'actual_weight' => 122.04,
        ]);

        $line->pieces()->create([
            'product_id' => ConsolidatedTestFactory::product('weight')->id,
            'barcode' => 'BLT5227',
            'description' => 'كباس زجاج ثابت -18ملم-25ملم-LE 5500',
            'piece_count' => 60,
            'piece_length' => 6,
            'weight_per_meter' => 0.339,
            'total_weight' => 122.04,
            'sort_order' => 0,
        ]);

        return $line->load(['product', 'pieces']);
    }

    public function test_a_weight_line_is_the_main_item_and_its_pieces_are_the_sub_items(): void
    {
        $line = $this->sapirLine();
        $main = PurchaseOrderMainItems::for([$line])[$line->id];

        $this->assertSame('المنيوم سبير F40 SAPIR', $main['name']);
        $this->assertSame('00088', $main['code']);
        $this->assertTrue($main['line_is_main']);
        $this->assertSame(122.04, $main['actual_weight']);
        $this->assertCount(1, $main['sub_items']);
        $this->assertSame('BLT5227', $main['sub_items'][0]['barcode']);
        $this->assertSame(60.0, $main['sub_items'][0]['count']);
        $this->assertSame(122.04, $main['sub_items'][0]['total_weight']);
    }

    public function test_searching_a_sub_item_shows_its_main_item_and_marks_the_match(): void
    {
        $line = $this->sapirLine();
        $main = PurchaseOrderMainItems::for([$line])[$line->id];

        $this->assertSame(['visible' => true, 'matched' => [0]], PurchaseOrderMainItems::search($main, 'كباس زجاج'));
        $this->assertSame(['visible' => true, 'matched' => [0]], PurchaseOrderMainItems::search($main, 'blt5227'));
        // اسم الصنف الرئيسي او كوده يُظهره كاملاً دون تمييز صنف فرعي.
        $this->assertSame(['visible' => true, 'matched' => []], PurchaseOrderMainItems::search($main, 'sapir'));
        $this->assertSame(['visible' => true, 'matched' => []], PurchaseOrderMainItems::search($main, '00088'));
        $this->assertFalse(PurchaseOrderMainItems::search($main, 'نابكو')['visible']);
    }

    public function test_length_and_quantity_match_the_pieces_of_the_main_item(): void
    {
        $line = $this->sapirLine();
        $main = PurchaseOrderMainItems::for([$line])[$line->id];

        $this->assertSame(['visible' => true, 'matched' => [0]], PurchaseOrderMainItems::search($main, '', '6'));
        $this->assertSame(['visible' => true, 'matched' => [0]], PurchaseOrderMainItems::search($main, '', '6.000', '60'));
        $this->assertFalse(PurchaseOrderMainItems::search($main, '', '4')['visible']);
        $this->assertFalse(PurchaseOrderMainItems::search($main, '', '6', '10')['visible']);
        // نص لا يطابق الرئيسي ولا الصنف الفرعي يُسقط المطابقة بالطول.
        $this->assertFalse(PurchaseOrderMainItems::search($main, 'نابكو', '6')['visible']);
        // الطول غير الرقمي يُتجاهل.
        $this->assertTrue(PurchaseOrderMainItems::search($main, '', 'abc')['visible']);
    }

    public function test_a_sub_item_entered_alone_is_shown_inside_its_main_product(): void
    {
        $mainProduct = ConsolidatedTestFactory::product('weight');
        $mainProduct->update(['sku' => '00301']);
        $mainProduct->translateOrNew('ar')->name = 'المنيوم سبير sapir 1200';
        $mainProduct->save();

        $subProduct = ConsolidatedTestFactory::product('weight');
        $subProduct->update(['main_product_id' => $mainProduct->id, 'weight_per_meter' => 1.873]);

        $line = ConsolidatedTestFactory::purchaseOrder()->items()->create([
            'product_id' => $subProduct->id,
            'description' => 'ثابت 3 مجاري 9000 كلبس 4500 K4593',
            'barcode' => 'K4593',
            'quantity' => 10,
            'length' => 6,
            'unit_price' => 21,
            'line_total' => 0,
            'total_weight' => 0,
            'actual_weight' => 20,
        ])->load(['product', 'pieces']);

        $main = PurchaseOrderMainItems::for([$line])[$line->id];

        $this->assertSame('المنيوم سبير sapir 1200', $main['name']);
        $this->assertSame('00301', $main['code']);
        $this->assertFalse($main['line_is_main']);
        $this->assertSame('ثابت 3 مجاري 9000 كلبس 4500 K4593', $main['sub_items'][0]['name']);
        $this->assertSame(10.0, $main['sub_items'][0]['count']);
        $this->assertSame(6.0, $main['sub_items'][0]['length']);
        $this->assertSame(1.873, $main['sub_items'][0]['weight_per_meter']);
    }

    public function test_a_weight_line_under_a_purchase_order_group_uses_the_group_as_its_main_item(): void
    {
        $category = Category::create(['slug' => 'napco-'.uniqid(), 'status' => 1]);
        $category->translateOrNew('ar')->name = 'المنيوم نابكو';
        $category->save();

        $line = ConsolidatedTestFactory::purchaseOrder()->items()->create([
            'category_id' => $category->id,
            'product_id' => ConsolidatedTestFactory::product('weight')->id,
            'description' => 'المنيوم مدهون نابكو',
            'quantity' => 1,
            'length' => 6,
            'unit_price' => 21,
            'line_total' => 105,
            'total_weight' => 0,
            'actual_weight' => 5,
        ])->load(['product', 'pieces']);

        $main = PurchaseOrderMainItems::for([$line])[$line->id];

        $this->assertSame('المنيوم نابكو', $main['name']);
        $this->assertSame('المنيوم مدهون نابكو', $main['sub_items'][0]['name']);
        $this->assertSame(['visible' => true, 'matched' => [0]], PurchaseOrderMainItems::search($main, '', '6'));
    }

    public function test_a_quantity_item_is_shown_alone_and_matches_its_own_quantity(): void
    {
        $line = ConsolidatedTestFactory::purchaseOrder()->items()->create([
            'product_id' => ConsolidatedTestFactory::product()->id,
            'description' => 'باب 2000 دفتين',
            'quantity' => 10,
            'unit_price' => 50,
            'line_total' => 500,
        ])->load(['product', 'pieces']);

        $main = PurchaseOrderMainItems::for([$line])[$line->id];

        $this->assertSame('باب 2000 دفتين', $main['name']);
        $this->assertSame([], $main['sub_items']);
        $this->assertFalse($main['is_weight_priced']);
        $this->assertTrue(PurchaseOrderMainItems::search($main, 'باب', null, '10')['visible']);
        $this->assertFalse(PurchaseOrderMainItems::search($main, '', '4')['visible']);
        $this->assertSame(['total_weight' => null, 'actual_weight' => null, 'pieces' => []], PurchaseOrderMainItems::weights($line));
    }

    public function test_row_weights_come_from_its_pieces_and_the_scale(): void
    {
        $weights = PurchaseOrderMainItems::weights($this->sapirLine());

        $this->assertSame(122.04, $weights['total_weight']);
        $this->assertSame(122.04, $weights['actual_weight']);
        $this->assertSame(6.0, $weights['pieces'][0]['length']);
    }
}
