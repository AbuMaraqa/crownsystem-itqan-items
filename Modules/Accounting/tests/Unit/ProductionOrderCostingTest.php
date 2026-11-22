<?php

namespace Modules\Accounting\tests\Unit;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Enums\LaborRateBasis;
use Modules\Accounting\Enums\ProductionOrderStatus;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Services\ProductionOrderService;
use Modules\Accounting\Support\ProductionCost;
use Modules\Accounting\tests\Feature\Concerns\PriceGroupFixtures;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ItemType;
use Modules\Items\Enums\ServiceCostSource;
use Tests\TestCase;

/**
 * قسم الإنتاج: تكلفة أمر الإنتاج = الاصناف + الأجور + الخدمات (داخلية او خارجية)،
 * وتكلفة الوحدة = الكلية ÷ الكمية المنتجة، وتُحسب تلقائياً عند الحفظ.
 */
class ProductionOrderCostingTest extends TestCase
{
    use PriceGroupFixtures;
    use RefreshDatabase;

    public function test_cost_rules(): void
    {
        $this->assertSame(240.0, ProductionCost::materialTotal(['pricing_type' => 'weight', 'quantity' => 3, 'weight' => 20, 'unit_cost' => 12]));
        $this->assertSame(36.0, ProductionCost::materialTotal(['pricing_type' => 'simple', 'quantity' => 3, 'weight' => 20, 'unit_cost' => 12]));
        $this->assertSame(150.0, ProductionCost::laborTotal(['quantity' => 6, 'rate' => 25]));
        $this->assertSame(100.0, ProductionCost::serviceTotal(['quantity' => '1,000', 'unit_cost' => '0.1']));

        $summary = ProductionCost::summary(
            [['pricing_type' => 'simple', 'quantity' => 10, 'unit_cost' => 30]],
            [['quantity' => 8, 'rate' => 25]],
            [['quantity' => 20, 'unit_cost' => 5]],
            4,
            1000,
        );

        $this->assertSame(300.0, $summary['materials_cost']);
        $this->assertSame(200.0, $summary['labor_cost']);
        $this->assertSame(100.0, $summary['services_cost']);
        $this->assertSame(600.0, $summary['total_cost']);
        $this->assertSame(150.0, $summary['unit_cost']);
        $this->assertSame(400.0, $summary['margin']);
        $this->assertSame(40.0, $summary['margin_rate']);
    }

    public function test_order_cost_is_calculated_on_save(): void
    {
        $this->actingAs(User::factory()->create());

        $aluminum = $this->product('weight', 9.5);
        $glass = $this->product('simple', 40);
        $painting = $this->service();
        $painter = User::factory()->create();

        $order = app(ProductionOrderService::class)->create([
            'order_date' => now()->toDateString(),
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'produced_quantity' => 5,
            'materials' => [
                ['product_id' => $aluminum->id, 'quantity' => 1, 'weight' => 120, 'unit_cost' => null],
                ['product_id' => $glass->id, 'quantity' => 5],
            ],
            'labor' => [
                ['worker_name' => 'عامل قص', 'description' => 'قص', 'rate_basis' => LaborRateBasis::HOUR->value, 'quantity' => 6, 'rate' => 20],
                ['worker_name' => 'فني تركيب', 'rate_basis' => LaborRateBasis::FIXED->value, 'quantity' => 9, 'rate' => 150],
            ],
            'services' => [
                ['product_id' => $painting->id, 'cost_source' => ServiceCostSource::EXTERNAL->value, 'service_provider_id' => $painter->id, 'provider_reference' => 'INV-77', 'quantity' => 120, 'unit_cost' => 2],
            ],
        ]);

        // اصناف: 120 كغم × 9.5 + 5 × 40 = 1340؛ أجور: 6 × 20 + مبلغ مقطوع 150 = 270؛ دهان: 120 × 2 = 240.
        $this->assertSame(1340.0, (float) $order->materials_cost);
        $this->assertSame(270.0, (float) $order->labor_cost);
        $this->assertSame(240.0, (float) $order->services_cost);
        $this->assertSame(1850.0, (float) $order->total_cost);
        $this->assertSame(370.0, (float) $order->unit_cost);
        $this->assertSame(ProductionOrderStatus::DRAFT, $order->status);
        $this->assertStringStartsWith('PROD', $order->reference_no);

        $service = $order->services->first();
        $this->assertSame($painter->id, (int) $service->service_provider_id);
        $this->assertSame('INV-77', $service->provider_reference);
        $this->assertSame(1.0, (float) $order->labor->last()->quantity);
    }

    public function test_internal_service_drops_the_external_provider(): void
    {
        $this->actingAs(User::factory()->create());

        $order = app(ProductionOrderService::class)->create([
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'services' => [
                ['product_id' => $this->service()->id, 'cost_source' => ServiceCostSource::INTERNAL->value, 'service_provider_id' => User::factory()->create()->id, 'quantity' => 10, 'unit_cost' => 3],
            ],
        ]);

        $this->assertNull($order->services->first()->service_provider_id);
        $this->assertSame(30.0, (float) $order->total_cost);
    }

    public function test_goods_cannot_be_a_service_line_and_costs_cannot_be_negative(): void
    {
        $this->actingAs(User::factory()->create());
        $currencyId = ConsolidatedTestFactory::currency()->id;

        try {
            app(ProductionOrderService::class)->create(['currency_id' => $currencyId, 'services' => [['product_id' => $this->product('simple', 5)->id, 'quantity' => 1]]]);
            $this->fail('A goods item was accepted as a service.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(DomainException::class);
        app(ProductionOrderService::class)->create(['currency_id' => $currencyId, 'labor' => [['worker_name' => 'x', 'quantity' => 1, 'rate' => -5]]]);
    }

    public function test_from_sales_order_with_workflow(): void
    {
        $this->actingAs(User::factory()->create());

        $aluminum = $this->product('weight', 9);
        $painting = $this->service();
        $salesOrder = $this->salesOrder();
        $salesOrder->items()->create(['product_id' => $aluminum->id, 'description' => 'المنيوم', 'quantity' => 1, 'unit_price' => 15, 'total_weight' => 100, 'actual_weight' => 98, 'line_total' => 1470]);
        $salesOrder->items()->create(['product_id' => $painting->id, 'line_type' => ItemType::SERVICE->value, 'pricing_basis' => 'weight', 'description' => 'دهان', 'quantity' => 98, 'unit_price' => 3, 'unit_cost' => 2.5, 'cost_source' => ServiceCostSource::EXTERNAL->value, 'line_total' => 294]);

        $service = app(ProductionOrderService::class);
        $order = $service->createFromSalesOrder($salesOrder->refresh());

        $this->assertSame($salesOrder->client_id, $order->client_id);
        $this->assertSame(1764.0, (float) $order->sales_amount);
        $this->assertSame(98.0, (float) $order->materials->first()->weight);
        $this->assertSame(882.0, (float) $order->materials_cost);
        $this->assertSame(ServiceCostSource::EXTERNAL->value, $order->services->first()->cost_source);
        $this->assertSame(245.0, (float) $order->services_cost);
        $this->assertSame(1127.0, (float) $order->total_cost);
        $this->assertSame(637.0, $order->margin());

        $order = $service->start($order);
        $this->assertSame(ProductionOrderStatus::IN_PROGRESS, $order->status);
        $this->assertSame(SalesOrderStatus::PREPARING, $salesOrder->refresh()->status);

        $order = $service->complete($order);
        $this->assertSame(ProductionOrderStatus::COMPLETED, $order->status);
        $this->assertNotNull($order->completed_at);
        $this->assertFalse($order->canBeEdited());

        try {
            $service->update($order, ['currency_id' => $order->currency_id]);
            $this->fail('A completed production order was updated.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $order = $service->reopen($order);
        $this->assertSame(ProductionOrderStatus::IN_PROGRESS, $order->status);
        $this->assertNull($order->completed_at);
    }

    public function test_empty_order_cannot_be_completed_and_cancelled_sales_order_cannot_be_produced(): void
    {
        $this->actingAs(User::factory()->create());
        $service = app(ProductionOrderService::class);

        $order = $service->create(['currency_id' => ConsolidatedTestFactory::currency()->id]);

        try {
            $service->complete($order);
            $this->fail('An order without cost was completed.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $salesOrder = $this->salesOrder();
        $salesOrder->update(['status' => SalesOrderStatus::CANCELLED->value()]);

        $this->expectException(DomainException::class);
        $service->createFromSalesOrder($salesOrder);
    }

    public function test_a_weight_material_stocked_by_piece_is_costed_per_kg(): void
    {
        $this->actingAs(User::factory()->create());
        // قطعة 6 م × 1.5 كغم/م = 9 كغم، متوسط تكلفتها 36 للقطعة = 4 للكغم.
        $profile = ConsolidatedTestFactory::product('weight', 6);
        ConsolidatedTestFactory::averageCost($profile, 36);
        $service = app(ProductionOrderService::class);

        $this->assertSame('4.0000', $service->materialDefaults($profile->id)['unit_cost']);

        $order = $service->create([
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'produced_quantity' => 1,
            'materials' => [['product_id' => $profile->id, 'quantity' => 10, 'weight' => 90]],
            'labor' => [],
            'services' => [],
        ]);

        // 90 كغم × 4، لا 90 × 36.
        $this->assertSame(360.0, (float) $order->materials_cost);
    }

    public function test_a_price_group_line_becomes_one_material_per_colour_at_its_cost_per_kg(): void
    {
        $this->actingAs(User::factory()->create());
        ['white' => $white, 'whiteW45' => $w45, 'whiteW60' => $w60] = $this->alpineGroups();
        ConsolidatedTestFactory::averageCost($w45, 32.5);   // 8.125 كغم للقطعة → 4 للكغم
        ConsolidatedTestFactory::averageCost($w60, 65);     // 13 كغم للقطعة → 5 للكغم
        $salesOrder = $this->salesOrder();
        $line = $salesOrder->items()->create(['product_id' => null, 'category_finish_id' => $white->id, 'description' => 'الوبين — أبيض', 'quantity' => 40, 'unit_price' => 8, 'total_weight' => 29.25, 'actual_weight' => 40, 'line_total' => 320]);
        $line->pieces()->create(['product_id' => $w45->id, 'description' => $w45->name, 'piece_count' => 2, 'piece_length' => 6.5, 'weight_per_meter' => 1.25, 'total_weight' => 16.25, 'sort_order' => 0]);
        $line->pieces()->create(['product_id' => $w60->id, 'description' => $w60->name, 'piece_count' => 1, 'piece_length' => 6.5, 'weight_per_meter' => 2, 'total_weight' => 13, 'sort_order' => 1]);

        $materials = app(ProductionOrderService::class)->linesFromSalesOrder($salesOrder->refresh())['materials'];

        $this->assertCount(2, $materials);
        $this->assertSame([$w45->id, $w60->id], array_column($materials, 'product_id'));
        $this->assertSame([22.2222, 17.7778], array_column($materials, 'weight'));
        $this->assertSame([4.0, 5.0], array_column($materials, 'unit_cost'));
        $this->assertSame(['weight', 'weight'], array_column($materials, 'pricing_type'));
    }

    private function product(string $pricingType, float $cost): Product
    {
        $product = ConsolidatedTestFactory::product($pricingType);
        // تكلفة المادة من متوسط تكلفة مشترياتها، لا من بطاقة الصنف.
        ConsolidatedTestFactory::averageCost($product, $cost);

        return $product;
    }

    private function service(): Product
    {
        $product = ConsolidatedTestFactory::product();
        $product->update(['item_type' => ItemType::SERVICE->value]);

        return $product;
    }

    private function salesOrder(): SalesOrder
    {
        return SalesOrder::create([
            'reference_no' => 'SO-'.Str::random(8),
            'client_id' => User::factory()->create()->id,
            'order_date' => now()->toDateString(),
            'currency_id' => ConsolidatedTestFactory::currency()->id,
            'exchange_rate' => 1,
            'status' => SalesOrderStatus::CONFIRMED->value(),
            'sub_total' => 1764,
            'total_amount' => 2081.52,
            'tax_amount' => 317.52,
        ]);
    }
}
