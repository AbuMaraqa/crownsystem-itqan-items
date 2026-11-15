<?php

namespace Modules\Accounting\tests\Unit;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\PurchaseOrderItem;
use Modules\Accounting\Support\PurchaseOrderConsumption;
use Tests\TestCase;

/**
 * السحب الجزئي من بنود طلبيات الشراء الى الطلبية التجميعية.
 */
class ConsolidatedPurchaseOrderItemQuantityTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(float $quantity, string $pricingType = 'simple'): PurchaseOrderItem
    {
        $product = ConsolidatedTestFactory::product($pricingType);
        $order = ConsolidatedTestFactory::purchaseOrder();

        return $order->items()->create([
            'product_id' => $product->id,
            'description' => 'بند اختبار',
            'quantity' => $quantity,
            'unit_price' => 10,
            'line_total' => $quantity * 10,
            'total_weight' => $pricingType === 'weight' ? $quantity : 0,
        ]);
    }

    public function test_it_defaults_to_the_full_remaining_quantity(): void
    {
        $item = $this->makeItem(5);

        $this->assertSame(5.0, PurchaseOrderConsumption::for()->resolveRequestedQuantity($item, null));
        $this->assertSame(5.0, PurchaseOrderConsumption::for()->resolveRequestedQuantity($item, ''));
    }

    public function test_it_accepts_a_partial_quantity(): void
    {
        $item = $this->makeItem(5);

        $this->assertSame(2.0, PurchaseOrderConsumption::for()->resolveRequestedQuantity($item, 2));
        $this->assertSame(0.5, PurchaseOrderConsumption::for()->resolveRequestedQuantity($item, 0.5));
    }

    public function test_it_rejects_a_quantity_above_the_remaining_amount(): void
    {
        $item = $this->makeItem(5);

        $this->expectException(DomainException::class);
        PurchaseOrderConsumption::for()->resolveRequestedQuantity($item, 6);
    }

    public function test_it_rejects_zero_and_negative_quantities(): void
    {
        $item = $this->makeItem(5);

        $this->expectException(DomainException::class);
        PurchaseOrderConsumption::for()->resolveRequestedQuantity($item, 0);
    }

    public function test_it_rejects_negative_quantities(): void
    {
        $item = $this->makeItem(5);

        $this->expectException(DomainException::class);
        PurchaseOrderConsumption::for()->resolveRequestedQuantity($item, -3);
    }

    public function test_weight_priced_items_are_always_pulled_in_full(): void
    {
        $item = $this->makeItem(4, 'weight');

        // حتى لو طُلبت كمية اقل، البند الموزون يُسحب كاملاً.
        $this->assertSame(4.0, PurchaseOrderConsumption::for()->resolveRequestedQuantity($item, 1));
    }

    public function test_payload_carries_the_requested_quantity_and_drops_pieces_for_simple_items(): void
    {
        $item = $this->makeItem(5);
        $payload = PurchaseOrderConsumption::payloadFor($item->load(['pieces', 'product']), 2.0);

        $this->assertSame(2.0, $payload['quantity']);
        $this->assertSame('simple', $payload['pricing_type']);
        $this->assertSame([], $payload['pieces']);
        $this->assertSame($item->id, $payload['source_purchase_order_item_id']);
    }

    public function test_editing_a_row_quantity_is_clamped_to_the_remaining_amount(): void
    {
        $item = $this->makeItem(5);

        $rows = [[
            'source_purchase_order_item_id' => $item->id,
            'pricing_type' => 'simple',
            'quantity' => 5,
        ]];

        $updated = PurchaseOrderConsumption::for()->applyRowQuantity($rows, 0, 3);
        $this->assertSame(3.0, $updated[0]['quantity']);

        $this->expectException(DomainException::class);
        PurchaseOrderConsumption::for()->applyRowQuantity($rows, 0, 99);
    }

    public function test_a_weight_item_without_sub_items_stays_available_until_pulled(): void
    {
        // صنف وزن بلا اصناف فرعية: وزنه الكلي صفر ووزنه الفعلي من الميزان.
        $item = $this->makeItem(1, 'weight');
        $item->update(['total_weight' => 0, 'actual_weight' => 1.27]);
        $item->load('product');

        $this->assertTrue(PurchaseOrderConsumption::for()->isAvailable($item, PurchaseOrderConsumption::for()->consumed([$item->id])));
        $this->assertSame(1.0, PurchaseOrderConsumption::for()->resolveRequestedQuantity($item, null));
        $this->assertNotContains($item->purchase_order_id, PurchaseOrderConsumption::for()->fullyConsumedPurchaseOrderIds());

        $this->pullIntoConsolidatedOrder($item);

        $this->assertFalse(PurchaseOrderConsumption::for()->isAvailable($item, PurchaseOrderConsumption::for()->consumed([$item->id])));
        $this->assertContains($item->purchase_order_id, PurchaseOrderConsumption::for()->fullyConsumedPurchaseOrderIds());

        $this->expectException(DomainException::class);
        PurchaseOrderConsumption::for()->resolveRequestedQuantity($item, null);
    }

    public function test_a_simple_item_stays_available_while_it_has_a_remaining_quantity(): void
    {
        $item = $this->makeItem(5);
        $item->load('product');

        $this->pullIntoConsolidatedOrder($item, 2);
        $this->assertTrue(PurchaseOrderConsumption::for()->isAvailable($item, PurchaseOrderConsumption::for()->consumed([$item->id])));

        $this->pullIntoConsolidatedOrder($item, 3);
        $this->assertFalse(PurchaseOrderConsumption::for()->isAvailable($item, PurchaseOrderConsumption::for()->consumed([$item->id])));
    }

    private function pullIntoConsolidatedOrder(PurchaseOrderItem $item, float $quantity = 1): void
    {
        ConsolidatedPurchaseOrder::create([
            'reference_no' => 'CPO-'.uniqid(),
            'order_date' => now()->toDateString(),
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'exchange_rate' => 1,
            'status' => 1,
        ])->items()->create([
            'source_purchase_order_item_id' => $item->id,
            'product_id' => $item->product_id,
            'description' => $item->description,
            'quantity' => $quantity,
            'unit_price' => 10,
            'line_total' => 10,
            'total_weight' => $item->total_weight,
            'actual_weight' => $item->actual_weight,
        ]);
    }

    public function test_row_limits_skip_weight_priced_rows(): void
    {
        $simple = $this->makeItem(5);
        $weight = $this->makeItem(4, 'weight');

        $limits = PurchaseOrderConsumption::for()->quantityLimitsForRows([
            ['source_purchase_order_item_id' => $simple->id, 'pricing_type' => 'simple'],
            ['source_purchase_order_item_id' => $weight->id, 'pricing_type' => 'weight'],
        ]);

        $this->assertSame(5.0, $limits[0]);
        $this->assertArrayNotHasKey(1, $limits, 'الصف الموزون لا يُعطى حداً لانه غير قابل للتعديل هنا.');
    }
}
