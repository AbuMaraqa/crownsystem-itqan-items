<?php

namespace Modules\Accounting\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Enums\ConsolidatedPurchaseOrderStatus;
use Modules\Accounting\Services\ConsolidatedPurchaseOrderService;
use Tests\TestCase;

/**
 * مراحل الطلبية التجميعية مع المورد.
 */
class ConsolidatedPurchaseOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $status = 1): ConsolidatedPurchaseOrder
    {
        return ConsolidatedPurchaseOrder::create([
            'reference_no' => 'CPO-'.uniqid(),
            'order_date' => now()->toDateString(),
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'exchange_rate' => 1,
            'status' => $status,
        ]);
    }

    private function service(): ConsolidatedPurchaseOrderService
    {
        return app(ConsolidatedPurchaseOrderService::class);
    }

    public function test_statuses_keep_the_previously_stored_numbers(): void
    {
        // الطلبيات القائمة خُزنت بقيم PurchaseOrderStatus، فيجب ان تُقرأ بلا ترحيل.
        $this->assertSame(ConsolidatedPurchaseOrderStatus::UNCONFIRMED, $this->order(1)->status);
        $this->assertSame(ConsolidatedPurchaseOrderStatus::CONFIRMED, $this->order(2)->status);
        $this->assertSame(ConsolidatedPurchaseOrderStatus::CONTAINER_LOADED, $this->order(3)->status);
        $this->assertSame(ConsolidatedPurchaseOrderStatus::READY_FOR_INVOICING, $this->order(4)->status);
        $this->assertSame(ConsolidatedPurchaseOrderStatus::CANCELLED, $this->order(5)->status);
        $this->assertCount(5, ConsolidatedPurchaseOrderStatus::options());
    }

    public function test_it_changes_the_status(): void
    {
        $order = $this->service()->changeStatus($this->order(), ConsolidatedPurchaseOrderStatus::CONTAINER_LOADED);

        $this->assertSame(ConsolidatedPurchaseOrderStatus::CONTAINER_LOADED, $order->status);
        $this->assertSame(3, (int) $order->getRawOriginal('status'));
    }

    public function test_deleting_marks_the_order_as_cancelled(): void
    {
        $order = $this->order(2);

        $this->service()->delete($order);

        $trashed = ConsolidatedPurchaseOrder::withTrashed()->find($order->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(ConsolidatedPurchaseOrderStatus::CANCELLED, $trashed->status);
    }
}
