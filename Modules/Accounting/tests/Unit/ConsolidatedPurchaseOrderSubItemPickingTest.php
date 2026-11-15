<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * المستخدم يختار الاصناف الفرعية من الصنف الرئيسي، لا الصنف الرئيسي كاملاً، وما يختاره من صنف
 * رئيسي واحد يظهر تحته في بند واحد — في صفحة الاضافة (الصفوف في النموذج) وتبويب الاصناف (يُحفظ فوراً).
 */
class ConsolidatedPurchaseOrderSubItemPickingTest extends TestCase
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
     * صنف رئيسي (بند وزن) فيه ثلاثة اصناف فرعية، اوزانها 10 و 20 و 30.
     *
     * @return array{0: PurchaseOrder, 1: PurchaseOrderItem, 2: array<int, int>}
     */
    private function purchaseOrder(): array
    {
        $po = ConsolidatedTestFactory::purchaseOrder();

        $main = $po->items()->create([
            'product_id' => ConsolidatedTestFactory::product('weight')->id,
            'description' => 'المنيوم سبير sapir 1200',
            'quantity' => 1,
            'unit_price' => 20,
            'line_total' => 1200,
            'total_weight' => 60,
            'actual_weight' => 58,
        ]);

        $pieceIds = [];

        foreach (['ثابت 3 مجاري', 'كلبس 4500', 'متحرك K4593'] as $index => $name) {
            $pieceIds[] = $main->pieces()->create([
                'product_id' => ConsolidatedTestFactory::product('weight')->id,
                'barcode' => 'SUB-'.($index + 1),
                'description' => $name,
                'piece_count' => 1,
                'piece_length' => 1,
                'weight_per_meter' => ($index + 1) * 10,
                'total_weight' => ($index + 1) * 10,
                'sort_order' => $index,
            ])->id;
        }

        return [$po, $main, $pieceIds];
    }

    private function consolidatedOrder(): ConsolidatedPurchaseOrder
    {
        return ConsolidatedPurchaseOrder::create([
            'reference_no' => 'CPO-'.uniqid(),
            'order_date' => now()->toDateString(),
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'exchange_rate' => 1,
            'status' => 1,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $keys
     * @return array<int, array<string, mixed>>
     */
    private function pull(array $rows, array $keys): array
    {
        return $this->postJson(route('accounting.consolidated-purchase-orders.picker.rows'), ['rows' => $rows, 'keys' => $keys])->assertOk()->json('rows');
    }

    private function store(array $rows): TestResponse
    {
        return $this->post(route('accounting.consolidated-purchase-orders.store'), ['order_date' => now()->toDateString(), 'items' => $rows]);
    }

    /**
     * @return array<int, string>
     */
    private function purchaseOrderLabels(): array
    {
        return array_column($this->getJson(route('accounting.consolidated-purchase-orders.picker.purchase-orders'))->assertOk()->json(), 'label');
    }

    public function test_add_page_pulls_only_the_ticked_sub_items_under_their_main_item(): void
    {
        [$po, $main, [$first, $second, $third]] = $this->purchaseOrder();

        $items = $this->pull([], ['piece-'.$first, 'piece-'.$third]);

        $this->assertCount(1, $items, 'الاصناف الفرعية من صنف رئيسي واحد في بند واحد.');
        $this->assertSame($main->id, $items[0]['source_purchase_order_item_id']);
        $this->assertSame([$first, $third], array_column($items[0]['pieces'], 'source_purchase_order_item_piece_id'));
        $this->assertSame('المنيوم سبير sapir 1200', $items[0]['main_item']['name']);
        $this->assertSame(['ثابت 3 مجاري', 'متحرك K4593'], array_column($items[0]['main_item']['sub_items'], 'name'));
        $this->assertEquals(40, $items[0]['main_item']['total_weight']);
        // وزن الميزان يخص الصنف الرئيسي باصنافه كلها.
        $this->assertNull($items[0]['actual_weight']);

        // ما يُختار لاحقاً من نفس الصنف الرئيسي يُضم الى بنده.
        $items = $this->pull($items, ['piece-'.$second]);

        $this->assertCount(1, $items);
        $this->assertSame([$first, $second, $third], array_column($items[0]['pieces'], 'source_purchase_order_item_piece_id'));
        $this->assertEquals(58, $items[0]['actual_weight']);

        $this->store($items)->assertRedirect();
        $saved = ConsolidatedPurchaseOrder::query()->latest('id')->first();

        $this->assertNotNull($saved, 'Consolidated order was not created.');
        $this->assertSame(1, $saved->items()->count());
        $this->assertSame([$first, $second, $third], $saved->items()->first()->pieces->pluck('source_purchase_order_item_piece_id')->all());
    }

    public function test_sub_items_pulled_elsewhere_are_hidden_and_the_rest_stay_available(): void
    {
        [$po, $main, [$first, $second, $third]] = $this->purchaseOrder();

        $this->store($this->pull([], ['piece-'.$first]))->assertRedirect();

        $saved = ConsolidatedPurchaseOrder::query()->latest('id')->first();
        $this->assertSame(10.0, (float) $saved->items()->first()->total_weight);
        // المبلغ بالوزن الفعلي، وهو فارغ عند السحب: صفر، ولو كان الوزن الكلي 10 كغم.
        $this->assertSame(0.0, (float) $saved->items()->first()->line_total);

        // طلبية تجميعية ثانية: الصنف الفرعي المسحوب لا يظهر، والباقي متاح.
        $this->assertContains($po->reference_no.' — ', $this->purchaseOrderLabels());

        $subItems = $this->postJson(route('accounting.consolidated-purchase-orders.picker.items'), ['purchase_order_id' => $po->id, 'rows' => []])
            ->assertOk()
            ->json('items.0.main.sub_items');
        $this->assertSame(['كلبس 4500', 'متحرك K4593'], array_column($subItems, 'name'));

        // تأشير صنف مسحوب سابقاً (من نافذة قديمة) لا يضيفه مرة ثانية.
        $items = $this->pull([], ['piece-'.$first, 'piece-'.$second, 'piece-'.$third]);

        $this->assertSame([$second, $third], array_column($items[0]['pieces'], 'source_purchase_order_item_piece_id'));

        $this->store($items)->assertRedirect();

        // كل الاصناف الفرعية سُحبت: طلبية الشراء لم تعد في القائمة.
        $this->assertNotContains($po->reference_no.' — ', $this->purchaseOrderLabels());
    }

    public function test_items_tab_merges_later_sub_items_into_the_same_main_item_row(): void
    {
        [$po, $main, [$first, $second, $third]] = $this->purchaseOrder();
        $record = $this->consolidatedOrder();

        $this->post(route('accounting.consolidated-purchase-orders.items.pull', $record), ['keys' => ['piece-'.$first]])->assertSessionHas('success');

        $item = $record->items()->sole();
        $this->assertSame([$first], $item->pieces->pluck('source_purchase_order_item_piece_id')->all());

        $this->post(route('accounting.consolidated-purchase-orders.items.pull', $record), ['keys' => ['piece-'.$second]])->assertSessionHas('success');

        $merged = $record->items()->sole();
        $this->assertSame($item->id, $merged->id, 'البند نفسه يُحدَّث ليبقى ما ارتبط به.');
        $this->assertSame([$first, $second], $merged->pieces->pluck('source_purchase_order_item_piece_id')->all());
        $this->assertSame(30.0, (float) $merged->total_weight);

        // الصنف الفرعي المضاف يظهر مؤشَّراً بانه مضاف، والباقي قابل للتأشير.
        $row = collect($this->postJson(route('accounting.consolidated-purchase-orders.picker.items'), ['purchase_order_id' => $po->id, 'order' => $record->id])->json('items'))
            ->firstWhere('id', $main->id);
        $this->assertSame([true, true, false], array_column($row['sub_item_keys'], 'added'));
        $this->assertSame(['piece-'.$third], $row['selectable_keys']);

        // حفظ رأس الطلبية يعيد بناء البنود: لا يمسح مصدر القطع ولا باركودها ووصفها كما سُحبت.
        $this->put(route('accounting.consolidated-purchase-orders.update', $record), ['order_date' => now()->toDateString(), 'notes' => 'متابعة'])->assertSessionHas('success');

        $pieces = $record->items()->sole()->pieces;
        $this->assertSame([$first, $second], $pieces->pluck('source_purchase_order_item_piece_id')->all());
        $this->assertSame(['SUB-1', 'SUB-2'], $pieces->pluck('barcode')->all());
        $this->assertSame('متابعة', $record->refresh()->notes);
    }

    public function test_a_line_pulled_before_sub_item_tracking_counts_as_fully_pulled(): void
    {
        [$po, $main] = $this->purchaseOrder();

        // بند سُحب كاملاً قبل حفظ مصدر القطع: قطعه بلا مصدر.
        $item = $this->consolidatedOrder()->items()->create([
            'source_purchase_order_item_id' => $main->id,
            'product_id' => $main->product_id,
            'description' => $main->description,
            'quantity' => 1,
            'unit_price' => 20,
            'line_total' => 1200,
            'total_weight' => 60,
        ]);
        $item->pieces()->create(['product_id' => $main->pieces()->value('product_id'), 'piece_count' => 1, 'total_weight' => 60]);

        $this->assertNotContains($po->reference_no.' — ', $this->purchaseOrderLabels());
    }
}
