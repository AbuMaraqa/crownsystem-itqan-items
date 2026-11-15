<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderItemPiece;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * الطلبية التجميعية تعرض كل بند صنفاً رئيسياً وبداخله اصنافه الفرعية، كما في شاشة النظام
 * القديم، في نافذة الاختيار وفي تبويب الاصناف.
 */
class ConsolidatedPurchaseOrderMainItemDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $user = User::factory()->create();

        foreach (['Create', 'Update', 'View List'] as $action) {
            $user->givePermissionTo(Permission::findOrCreate('Consolidated Purchase Order '.$action, 'web'));
        }

        $this->actingAs($user);
    }

    /**
     * @return array{0: PurchaseOrder, 1: PurchaseOrderItem, 2: PurchaseOrderItem}
     */
    private function purchaseOrder(): array
    {
        $po = ConsolidatedTestFactory::purchaseOrder();

        $mainProduct = ConsolidatedTestFactory::product('weight');
        $mainProduct->update(['sku' => '00088']);

        $sapir = $po->items()->create([
            'product_id' => $mainProduct->id,
            'description' => 'المنيوم سبير F40 SAPIR',
            'quantity' => 1,
            'unit_price' => 21,
            'line_total' => 2562.84,
            'total_weight' => 122.04,
            'actual_weight' => 122.04,
        ]);

        $sapir->pieces()->create([
            'product_id' => ConsolidatedTestFactory::product('weight')->id,
            'barcode' => 'BLT5227',
            'description' => 'كباس زجاج ثابت -18ملم-25ملم-LE 5500',
            'piece_count' => 60,
            'piece_length' => 6,
            'weight_per_meter' => 0.339,
            'total_weight' => 122.04,
            'sort_order' => 0,
        ]);

        $door = $po->items()->create([
            'product_id' => ConsolidatedTestFactory::product()->id,
            'description' => 'باب 2000 دفتين',
            'quantity' => 10,
            'unit_price' => 50,
            'line_total' => 500,
        ]);

        return [$po, $sapir, $door];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    private function pickerItems(PurchaseOrder $po, array $body = []): array
    {
        return $this->postJson(route('accounting.consolidated-purchase-orders.picker.items'), ['purchase_order_id' => $po->id, ...$body])->assertOk()->json('items');
    }

    public function test_add_modal_shows_the_main_item_with_its_sub_items_inside(): void
    {
        [$po, $sapir] = $this->purchaseOrder();

        $items = $this->pickerItems($po, ['rows' => []]);

        $this->assertSame(['المنيوم سبير F40 SAPIR', 'باب 2000 دفتين'], array_column(array_column($items, 'main'), 'name'));
        $this->assertSame('00088', $items[0]['main']['code']);
        $this->assertEquals(122.04, $items[0]['main']['total_weight']);
        $this->assertEquals([[
            'name' => 'كباس زجاج ثابت -18ملم-25ملم-LE 5500',
            'barcode' => 'BLT5227',
            'count' => 60,
            'length' => 6,
            'weight_per_meter' => 0.339,
            'total_weight' => 122.04,
        ]], $items[0]['main']['sub_items']);

        // البحث بالصنف الفرعي يُظهر صنفه الرئيسي كاملاً.
        $found = $this->pickerItems($po, ['rows' => [], 'q' => 'BLT5227']);
        $this->assertSame(['المنيوم سبير F40 SAPIR'], array_column(array_column($found, 'main'), 'name'));
        $this->assertSame([0], $found[0]['matched']);

        $byLength = $this->pickerItems($po, ['rows' => [], 'length' => '6']);
        $this->assertSame(['المنيوم سبير F40 SAPIR'], array_column(array_column($byLength, 'main'), 'name'));

        // صنف الوزن بقطعه يُؤشَّر من اصنافه الفرعية؛ الاضافة تنسخ القطعة كما سُجّلت.
        $rows = $this->postJson(route('accounting.consolidated-purchase-orders.picker.rows'), ['rows' => [], 'keys' => ['piece-'.$sapir->pieces()->value('id')]])->assertOk()->json('rows');

        $this->assertSame($sapir->id, $rows[0]['source_purchase_order_item_id']);
        $this->assertSame('المنيوم سبير F40 SAPIR', $rows[0]['main_item']['name']);
        $this->assertSame('BLT5227', $rows[0]['pieces'][0]['barcode']);
    }

    public function test_items_tab_shows_main_items_with_their_sub_items(): void
    {
        [$po, $sapir, $door] = $this->purchaseOrder();

        $record = ConsolidatedPurchaseOrder::create([
            'reference_no' => 'CPO-'.uniqid(),
            'order_date' => now()->toDateString(),
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'exchange_rate' => 1,
            'status' => 1,
        ]);

        // نافذة الاضافة في صفحة التعديل هي نفسها في صفحة الاضافة.
        $this->assertSame(['المنيوم سبير F40 SAPIR'], array_column(array_column($this->pickerItems($po, ['order' => $record->id, 'length' => '6']), 'main'), 'name'));

        $this->post(route('accounting.consolidated-purchase-orders.items.pull', $record), ['keys' => ['piece-'.$sapir->pieces()->value('id')]])->assertSessionHas('success');
        $this->post(route('accounting.consolidated-purchase-orders.items.pull', $record), ['purchase_order_item_id' => $door->id])->assertSessionHas('success', __('Item added.'));

        $this->assertSame(2, $record->items()->count());
        // القطعة تُنسخ كما سُجّلت في طلبية الشراء، مع مصدرها.
        $this->assertSame('BLT5227', ConsolidatedPurchaseOrderItemPiece::query()->value('barcode'));
        $this->assertSame($sapir->pieces()->value('id'), ConsolidatedPurchaseOrderItemPiece::query()->value('source_purchase_order_item_piece_id'));

        $this->get(route('accounting.consolidated-purchase-orders.edit', $record))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/ConsolidatedPurchaseOrder/Edit')
                ->has('items.rows', 2)
                ->where('items.has_weight_items', true)
                ->where('items.rows.0.main.name', 'المنيوم سبير F40 SAPIR')
                ->where('items.rows.0.main.code', '00088')
                ->where('items.rows.0.main.sub_items.0.name', 'كباس زجاج ثابت -18ملم-25ملم-LE 5500')
                ->where('items.rows.0.main.sub_items.0.barcode', 'BLT5227')
                ->where('items.rows.0.source.reference_no', $po->reference_no)
                ->where('items.rows.1.main.name', 'باب 2000 دفتين')
                ->where('items.rows.1.quantity', '10')
                // المتبقي لهذه الطلبية: ما سُحب فيها لا يُحتسب، فيبقى حد تعديل الكمية 10.
                ->where('items.rows.1.remaining', fn ($value): bool => (float) $value === 10.0)
                ->where('counts.items', 2));
    }
}
