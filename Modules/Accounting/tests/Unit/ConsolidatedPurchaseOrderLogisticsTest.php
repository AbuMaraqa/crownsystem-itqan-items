<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Accounting\Entities\AdditionalCostType;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderItem;
use Modules\Accounting\Entities\ReappraisalElement;
use Modules\Accounting\Enums\ReappraisalStatus;
use Modules\Accounting\Enums\ShippingClassification;
use Modules\Accounting\Services\ConsolidatedPurchaseOrderLogisticsService;
use Modules\Core\Entities\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * التكاليف الاضافية، اعادة التخمين، وتغطية العملة داخل الطلبية التجميعية.
 */
class ConsolidatedPurchaseOrderLogisticsTest extends TestCase
{
    use RefreshDatabase;

    private function order(): ConsolidatedPurchaseOrder
    {
        return ConsolidatedPurchaseOrder::create([
            'reference_no' => 'CPO-'.uniqid(),
            'order_date' => now()->toDateString(),
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'exchange_rate' => 1,
            'status' => 1,
        ]);
    }

    private function service(): ConsolidatedPurchaseOrderLogisticsService
    {
        return app(ConsolidatedPurchaseOrderLogisticsService::class);
    }

    /* ---------------------- التكاليف الاضافية ---------------------- */

    public function test_additional_cost_types_options_only_return_active_ones(): void
    {
        AdditionalCostType::create(['name' => 'عمولة بنكية', 'is_active' => true]);
        AdditionalCostType::create(['name' => 'بند موقوف', 'is_active' => false]);

        $options = AdditionalCostType::options();

        $this->assertContains('عمولة بنكية', $options);
        $this->assertNotContains('بند موقوف', $options);
    }

    public function test_it_creates_updates_and_deletes_an_additional_cost(): void
    {
        $order = $this->order();
        $type = AdditionalCostType::create(['name' => 'مصاريف ميناء', 'is_active' => true]);

        $cost = $this->service()->createAdditionalCost($order, [
            'additional_cost_type_id' => $type->id,
            'details' => 'رسوم ارضيات',
            'amount' => 150.5,
            'currency_id' => ConsolidatedTestFactory::currency()->id,
        ]);

        $this->assertSame(150.5, (float) $cost->amount);
        $this->assertSame($type->id, $cost->additionalCostType->id);
        $this->assertSame(1, $order->additionalCosts()->count());

        $this->service()->updateAdditionalCost($cost, ['amount' => 200]);
        $this->assertSame(200.0, (float) $cost->refresh()->amount);

        $this->service()->deleteAdditionalCost($cost);
        $this->assertSame(0, $order->additionalCosts()->count());
        $this->assertNotNull($cost->fresh()->deleted_at, 'الحذف يجب ان يكون ناعماً.');
    }

    public function test_soft_deleted_cost_type_keeps_existing_costs_readable(): void
    {
        $order = $this->order();
        $type = AdditionalCostType::create(['name' => 'بند سيُحذف', 'is_active' => true]);

        $cost = $this->service()->createAdditionalCost($order, [
            'additional_cost_type_id' => $type->id,
            'amount' => 50,
            'currency_id' => ConsolidatedTestFactory::currency()->id,
        ]);

        $type->delete();

        // الحذف الناعم يبقي العلاقة قابلة للقراءة عبر withTrashed.
        $this->assertNotNull($cost->fresh());
        $this->assertNotNull(
            AdditionalCostType::withTrashed()->find($type->id),
            'البند المحذوف يجب ان يظل موجوداً حتى لا تفقد التكاليف مرجعها.'
        );
    }

    /* ---------------------- اعادة التخمين ---------------------- */

    public function test_reappraisal_difference_is_computed_from_both_values(): void
    {
        $order = $this->order();

        $reappraisal = $this->service()->createReappraisal($order, [
            'reappraisal_date' => now()->toDateString(),
            'declared_value' => 1000,
            'reappraised_value' => 1250.5,
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'status' => ReappraisalStatus::PENDING->value(),
        ]);

        $this->assertSame(250.5, $reappraisal->difference);

        // الفرق يتبع اي تعديل لان القيمة غير مخزَّنة.
        $this->service()->updateReappraisal($reappraisal, ['reappraised_value' => 900]);
        $this->assertSame(-100.0, $reappraisal->refresh()->difference);
    }

    public function test_reappraisal_status_is_cast_to_the_enum(): void
    {
        $order = $this->order();

        $reappraisal = $this->service()->createReappraisal($order, [
            'reappraisal_date' => now()->toDateString(),
            'declared_value' => 100,
            'reappraised_value' => 100,
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'status' => ReappraisalStatus::APPEALED->value(),
        ]);

        $this->assertInstanceOf(ReappraisalStatus::class, $reappraisal->refresh()->status);
        $this->assertSame(ReappraisalStatus::APPEALED, $reappraisal->status);
        $this->assertSame(0.0, $reappraisal->difference);
    }

    public function test_reappraisal_enum_exposes_all_statuses(): void
    {
        $this->assertSame([1, 2, 3, 4], array_keys(ReappraisalStatus::options()));
    }

    public function test_reappraisal_elements_options_only_return_active_ones(): void
    {
        ReappraisalElement::create(['name' => 'رسوم جمركية', 'is_active' => true]);
        ReappraisalElement::create(['name' => 'بند موقوف', 'is_active' => false]);

        $options = ReappraisalElement::options();

        $this->assertContains('رسوم جمركية', $options);
        $this->assertNotContains('بند موقوف', $options);
    }

    public function test_reappraisal_stores_its_elements_and_affected_items(): void
    {
        $order = $this->order();
        [$itemA, $itemB] = $this->orderItems($order);
        $customs = ReappraisalElement::create(['name' => 'رسوم جمركية', 'is_active' => true]);
        $vat = ReappraisalElement::create(['name' => 'ضريبة القيمة المضافة', 'is_active' => true]);

        $reappraisal = $this->service()->createReappraisal($order, [
            ...$this->reappraisalData(),
            'lines' => [
                ['reappraisal_element_id' => $customs->id, 'amount' => 300, 'notes' => 'فرق السعر'],
                ['reappraisal_element_id' => $vat->id, 'amount' => 54.5, 'notes' => null],
                // صف اضيف ولم يُختر له بند لا يُحفظ.
                ['reappraisal_element_id' => null, 'amount' => 99, 'notes' => null],
            ],
            'items' => [
                ['consolidated_purchase_order_item_id' => $itemA->id, 'selected' => true, 'notes' => 'سعر الكيلو'],
                ['consolidated_purchase_order_item_id' => $itemB->id, 'selected' => false, 'notes' => 'ملاحظة على صنف غير مؤشَّر'],
            ],
        ]);

        $this->assertSame(2, $reappraisal->lines()->count());
        $this->assertSame(354.5, (float) $reappraisal->lines()->sum('amount'));
        $this->assertSame('فرق السعر', $reappraisal->lines()->where('reappraisal_element_id', $customs->id)->value('notes'));

        $this->assertSame([$itemA->id], $reappraisal->items()->pluck('consolidated_purchase_order_item_id')->all());
        $this->assertSame('سعر الكيلو', $reappraisal->items()->value('notes'));
    }

    public function test_reappraisal_update_replaces_elements_and_items_only_when_sent(): void
    {
        $order = $this->order();
        [$itemA, $itemB] = $this->orderItems($order);
        $customs = ReappraisalElement::create(['name' => 'رسوم جمركية', 'is_active' => true]);
        $fine = ReappraisalElement::create(['name' => 'غرامة', 'is_active' => true]);

        $reappraisal = $this->service()->createReappraisal($order, [
            ...$this->reappraisalData(),
            'lines' => [['reappraisal_element_id' => $customs->id, 'amount' => 100]],
            'items' => [['consolidated_purchase_order_item_id' => $itemA->id, 'selected' => true]],
        ]);

        // تعديل جزئي بلا بنود ولا اصناف لا يمسح المحفوظ منها.
        $this->service()->updateReappraisal($reappraisal, ['reappraised_value' => 1500]);
        $this->assertSame(1, $reappraisal->lines()->count());
        $this->assertSame(1, $reappraisal->items()->count());

        $this->service()->updateReappraisal($reappraisal, [
            'lines' => [['reappraisal_element_id' => $fine->id, 'amount' => 75]],
            'items' => [
                ['consolidated_purchase_order_item_id' => $itemA->id, 'selected' => false],
                ['consolidated_purchase_order_item_id' => $itemB->id, 'selected' => true],
            ],
        ]);

        $this->assertSame([$fine->id], $reappraisal->lines()->pluck('reappraisal_element_id')->all());
        $this->assertSame([$itemB->id], $reappraisal->items()->pluck('consolidated_purchase_order_item_id')->all());
    }

    public function test_deleting_a_reappraisal_removes_its_elements_and_items(): void
    {
        $order = $this->order();
        [$itemA] = $this->orderItems($order);
        $customs = ReappraisalElement::create(['name' => 'رسوم جمركية', 'is_active' => true]);

        $reappraisal = $this->service()->createReappraisal($order, [
            ...$this->reappraisalData(),
            'lines' => [['reappraisal_element_id' => $customs->id, 'amount' => 100]],
            'items' => [['consolidated_purchase_order_item_id' => $itemA->id, 'selected' => true]],
        ]);

        $this->service()->deleteReappraisal($reappraisal);

        $this->assertNotNull($reappraisal->fresh()->deleted_at, 'الحذف يجب ان يكون ناعماً.');
        $this->assertSame(0, $reappraisal->lines()->count());
        $this->assertSame(0, $reappraisal->items()->count());
    }

    public function test_soft_deleted_element_keeps_its_name_on_saved_reappraisals(): void
    {
        $order = $this->order();
        $customs = ReappraisalElement::create(['name' => 'بند سيُحذف', 'is_active' => true]);

        $reappraisal = $this->service()->createReappraisal($order, [
            ...$this->reappraisalData(),
            'lines' => [['reappraisal_element_id' => $customs->id, 'amount' => 100]],
        ]);

        $customs->delete();

        $this->assertSame('بند سيُحذف', $reappraisal->lines()->first()->element->name);
    }

    /**
     * صفحة الطلبية (Vue) وتبويباتها: المستخدم بصلاحية التعديل، والمسارات قبل دمجها.
     */
    private function signInForTabs(): void
    {
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('Consolidated Purchase Order Update', 'web'));
        $this->actingAs($user);
    }

    public function test_reappraisals_tab_saves_elements_and_affected_items_from_the_form(): void
    {
        $this->signInForTabs();
        $order = $this->order();
        [$itemA, $itemB] = $this->orderItems($order);
        $customs = ReappraisalElement::create(['name' => 'رسوم جمركية', 'is_active' => true]);

        // كل اصناف الطلبية معروضة للتأشير، غير مؤشَّرة.
        $this->get(route('accounting.consolidated-purchase-orders.edit', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orderItems.0.id', $itemA->id)
                ->where('orderItems.1.id', $itemB->id));

        $this->post(route('accounting.consolidated-purchase-orders.tabs.store', ['consolidatedPurchaseOrder' => $order->id, 'tab' => 'reappraisals']), [
            ...$this->reappraisalData(),
            'lines' => [['reappraisal_element_id' => $customs->id, 'amount' => 420, 'notes' => 'فرق السعر']],
            'items' => [
                ['consolidated_purchase_order_item_id' => $itemA->id, 'selected' => true, 'notes' => null],
                ['consolidated_purchase_order_item_id' => $itemB->id, 'selected' => false, 'notes' => 'غير مؤشَّر'],
            ],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $reappraisal = $order->reappraisals()->first();

        $this->assertNotNull($reappraisal);
        $this->assertSame(420.0, (float) $reappraisal->lines()->value('amount'));
        $this->assertSame([$itemA->id], $reappraisal->items()->pluck('consolidated_purchase_order_item_id')->all());

        // التعديل يعيد ملء البنود والاصناف المؤشَّرة كما حُفظت.
        $this->get(route('accounting.consolidated-purchase-orders.edit', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reappraisals.0.form.lines', [['reappraisal_element_id' => $customs->id, 'amount' => '420', 'notes' => 'فرق السعر']])
                ->where('reappraisals.0.form.items', [['id' => $itemA->id, 'notes' => null]])
                ->where('reappraisals.0.difference', '250.00')
                ->where('counts.reappraisal', 1));

        // البند الواحد بصف واحد.
        $this->put(route('accounting.consolidated-purchase-orders.tabs.update', ['consolidatedPurchaseOrder' => $order->id, 'tab' => 'reappraisals', 'record' => $reappraisal->id]), [
            ...$this->reappraisalData(),
            'lines' => [['reappraisal_element_id' => $customs->id, 'amount' => 1], ['reappraisal_element_id' => $customs->id, 'amount' => 2]],
        ])->assertSessionHasErrors('lines.1.reappraisal_element_id');
    }

    public function test_additional_costs_tab_still_saves_selected_items_from_the_form(): void
    {
        $this->signInForTabs();
        $order = $this->order();
        [$itemA, $itemB] = $this->orderItems($order);
        $type = AdditionalCostType::create(['name' => 'مصاريف ميناء', 'is_active' => true]);

        $this->post(route('accounting.consolidated-purchase-orders.tabs.store', ['consolidatedPurchaseOrder' => $order->id, 'tab' => 'additional-costs']), [
            'additional_cost_type_id' => $type->id,
            'amount' => 150,
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'items' => [
                ['consolidated_purchase_order_item_id' => $itemA->id, 'selected' => false, 'notes' => null],
                ['consolidated_purchase_order_item_id' => $itemB->id, 'selected' => true, 'notes' => 'رسوم ارضيات'],
            ],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $cost = $order->additionalCosts()->first();

        $this->assertSame([$itemB->id], $cost->items()->pluck('consolidated_purchase_order_item_id')->all());
        $this->assertSame('رسوم ارضيات', $cost->items()->value('notes'));
    }

    public function test_reappraisal_elements_page_creates_an_element(): void
    {
        $user = User::factory()->create();

        foreach (['Reappraisal Element View List', 'Reappraisal Element Create'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $user->givePermissionTo(['Reappraisal Element View List', 'Reappraisal Element Create']);
        $this->actingAs($user);

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $this->post(route('accounting.reappraisal-elements.store'), ['name' => 'غرامة تأخير', 'is_active' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $element = ReappraisalElement::query()->where('name', 'غرامة تأخير')->first();

        $this->assertNotNull($element);
        $this->assertTrue($element->is_active);
        $this->assertSame($user->id, $element->created_by);
    }

    /**
     * @return array<string, mixed>
     */
    private function reappraisalData(): array
    {
        return [
            'reappraisal_date' => now()->toDateString(),
            'declared_value' => 1000,
            'reappraised_value' => 1250,
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'status' => ReappraisalStatus::PENDING->value(),
        ];
    }

    /**
     * @return array<int, ConsolidatedPurchaseOrderItem>
     */
    private function orderItems(ConsolidatedPurchaseOrder $order): array
    {
        return [
            $order->items()->create(['description' => 'المنيوم سبير 1200', 'quantity' => 10]),
            $order->items()->create(['description' => 'المنيوم نابكو', 'quantity' => 4]),
        ];
    }

    /* ---------------------- العملة والكنتينر ---------------------- */

    public function test_every_priced_record_can_carry_a_currency(): void
    {
        $entities = [
            \Modules\Accounting\Entities\ConsolidatedPurchaseOrderShipment::class,
            \Modules\Accounting\Entities\ConsolidatedPurchaseOrderAdditionalCost::class,
            \Modules\Accounting\Entities\ConsolidatedPurchaseOrderReappraisal::class,
            \Modules\Accounting\Entities\ConsolidatedPurchaseOrderClearance::class,
            \Modules\Accounting\Entities\ConsolidatedPurchaseOrderDelivery::class,
            \Modules\Accounting\Entities\ConsolidatedPurchaseOrderPayment::class,
            \Modules\Accounting\Entities\ConsolidatedPurchaseOrderBankCredit::class,
        ];

        foreach ($entities as $class) {
            $model = new $class;

            $this->assertContains('currency_id', $model->getFillable(), $class.' لا يقبل العملة.');
            $this->assertTrue(method_exists($model, 'currency'), $class.' بلا علاقة عملة.');
        }
    }

    public function test_shipment_stores_container_and_invoice_numbers(): void
    {
        $order = $this->order();

        $shipment = $this->service()->createShipment($order, [
            'shipping_type' => 1,
            'shipping_classification' => ShippingClassification::CONTAINER->value(),
            'container_number' => 'MSKU1234567',
            'invoice_number' => 'INV-9001',
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'price' => 500,
        ]);

        $shipment->refresh();

        $this->assertSame('MSKU1234567', $shipment->container_number);
        $this->assertSame('INV-9001', $shipment->invoice_number);
        $this->assertSame(ShippingClassification::CONTAINER, $shipment->shipping_classification);
    }

    public function test_clearance_stores_total_cost_with_currency(): void
    {
        $order = $this->order();

        $clearance = $this->service()->createClearance($order, [
            'total_cost' => 780.25,
            'currency_id' => ConsolidatedTestFactory::currency()->id,
        ]);

        $clearance->refresh();

        $this->assertSame(780.25, (float) $clearance->total_cost);
        $this->assertSame(ConsolidatedTestFactory::currency()->id, $clearance->currency_id);
        $this->assertSame('ILS', $clearance->currency->code);
    }
}
