<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\SalesOrderItem;
use Modules\Accounting\Services\PurchaseInvoiceService;
use Modules\Core\Entities\User;
use Tests\TestCase;

/**
 * فوترة الطلبية: الصنف الموزون يُفوتر بوزنه × سعر الكغم لا بكميته × سعر الكغم.
 */
class WeightOrderInvoicingTest extends TestCase
{
    use RefreshDatabase;

    public function test_weight_line_is_invoiced_by_its_total_weight(): void
    {
        $order = $this->orderWithLine('weight', ['quantity' => 1, 'unit_price' => 3.2, 'total_weight' => 2500, 'line_total' => 8000]);

        $invoice = app(PurchaseInvoiceService::class)->createFromOrder($order);

        $this->assertSame(2500.0, (float) $invoice->items->first()->quantity);
        $this->assertSame(8000.0, (float) $invoice->items->first()->line_total);
        $this->assertSame(8000.0, (float) $invoice->total_amount);
    }

    public function test_actual_weight_wins_over_the_total_weight(): void
    {
        $order = $this->orderWithLine('weight', [
            'quantity' => 1,
            'unit_price' => 3.2,
            'total_weight' => 2500,
            'actual_weight' => 2400,
            'line_total' => 7680,
        ]);

        $invoice = app(PurchaseInvoiceService::class)->createFromOrder($order);

        $this->assertSame(2400.0, (float) $invoice->items->first()->quantity);
        $this->assertSame(7680.0, (float) $invoice->total_amount);
    }

    public function test_quantity_line_keeps_its_quantity(): void
    {
        $order = $this->orderWithLine('simple', ['quantity' => 3, 'unit_price' => 8, 'line_total' => 24]);

        $invoice = app(PurchaseInvoiceService::class)->createFromOrder($order);

        $this->assertSame(3.0, (float) $invoice->items->first()->quantity);
        $this->assertSame(24.0, (float) $invoice->total_amount);
    }

    public function test_sales_order_item_uses_the_same_rule(): void
    {
        $this->assertSame(480.0, (new SalesOrderItem(['quantity' => 1, 'total_weight' => 500, 'actual_weight' => 480]))->invoiceQuantity());
        $this->assertSame(500.0, (new SalesOrderItem(['quantity' => 1, 'total_weight' => 500]))->invoiceQuantity());
        $this->assertSame(4.0, (new SalesOrderItem(['quantity' => 4, 'total_weight' => 0]))->invoiceQuantity());
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function orderWithLine(string $pricingType, array $line): PurchaseOrder
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = ConsolidatedTestFactory::product($pricingType);
        $order = ConsolidatedTestFactory::purchaseOrder();
        $order->update([
            'client_id' => $user->id,
            'sub_total' => $line['line_total'],
            'total_amount' => $line['line_total'],
        ]);

        $order->items()->create(array_merge(['product_id' => $product->id, 'description' => 'بند اختبار'], $line));

        return $order;
    }
}
