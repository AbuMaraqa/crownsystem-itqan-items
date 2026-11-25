<?php

namespace Modules\Accounting\tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Entities\ProductCost;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Entities\StockCostLayer;
use Modules\Accounting\Entities\StockCostLayerEntry;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Tests\TestCase;

/**
 * تكلفة FIFO كما في اتقان: البيع يستهلك اقدم مشتريات الصنف في نفس المخزن بسعرها. الارقام
 * محسوبة يدوياً في التعليقات.
 */
class StockCostLayersTest extends TestCase
{
    use RefreshDatabase;

    private StockLedgerService $ledger;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->ledger = app(StockLedgerService::class);
        $this->warehouse = $this->makeWarehouse('WH1');
        $this->product = $this->makeProduct();
    }

    public function test_a_sale_spanning_two_purchases_consumes_the_oldest_first(): void
    {
        $this->in(5, 10);
        $this->in(5, 12);

        $movements = $this->out(7);

        // 5 × 10 + 2 × 12 = 74.
        $this->assertSame(-74.0, (float) $movements->sum('total_cost'));
        // الباقي 3 × 12 = 36.
        $this->assertSame(3.0, (float) ProductCost::query()->where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(36.0, (float) ProductCost::query()->where('product_id', $this->product->id)->value('value'));
        $this->assertSame(12.0, $this->ledger->averageCost($this->product->id));
        $this->assertSame(36.0, $this->layersValue());
    }

    public function test_the_next_sale_continues_where_the_last_stopped(): void
    {
        $this->in(5, 10);
        $this->in(5, 12);
        $this->out(7);

        // الـ 3 الباقية بسعر 12.
        $this->assertSame(-36.0, (float) $this->out(3)->sum('total_cost'));
        $this->assertSame(0.0, $this->layersValue());
        $this->assertSame(0.0, (float) ProductCost::query()->where('product_id', $this->product->id)->value('value'));
    }

    public function test_a_zero_priced_layer_is_consumed_at_zero_like_any_other(): void
    {
        $this->in(2, 0);
        $this->in(2, 10);

        // اتقان لا يتجاوز سطر الشراء بسعر صفر: 2 × 0 + 1 × 10 = 10.
        $this->assertSame(-10.0, (float) $this->out(3)->sum('total_cost'));
    }

    public function test_quantity_without_a_layer_costs_zero(): void
    {
        // رصيد بلا طبقة: اتقان يترك الخروج بلا ربط وبتكلفة صفر.
        StockBalance::query()->create(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'location_id' => 0, 'quantity' => 5, 'weight' => 0]);
        ProductCost::query()->create(['product_id' => $this->product->id, 'quantity' => 5, 'value' => 0, 'average_cost' => 0]);
        $this->in(2, 10);

        // 2 × 10 + 3 × 0 = 20.
        $this->assertSame(-20.0, (float) $this->out(5)->sum('total_cost'));
    }

    public function test_layers_are_kept_per_warehouse(): void
    {
        $second = $this->makeWarehouse('WH2');
        $this->in(5, 10);
        $this->in(5, 20, $second);

        // المخزن الثاني يخرج بسعر مشترياته هو ولو كان الاقدم في مخزن آخر.
        $this->assertSame(-40.0, (float) $this->out(2, $second)->sum('total_cost'));
        $this->assertSame(-10.0, (float) $this->out(1)->sum('total_cost'));
    }

    public function test_reversing_a_sale_restores_the_same_layers_exactly(): void
    {
        $this->in(5, 10);
        $this->in(5, 12);
        $source = $this->makeProduct();
        $this->ledger->issue($this->movement(['quantity' => 7, 'type' => StockMovementType::DELIVERY, 'source' => $source]));

        $this->ledger->reverse($source, now()->toDateString());

        $layers = StockCostLayer::query()->where('product_id', $this->product->id)->orderBy('sequence')->get();
        $this->assertSame([5.0, 5.0], $layers->map(fn ($layer) => (float) $layer->remaining_quantity)->all());
        $this->assertSame([50.0, 60.0], $layers->map(fn ($layer) => (float) $layer->remaining_value)->all());
        $this->assertSame(110.0, (float) ProductCost::query()->where('product_id', $this->product->id)->value('value'));
        $this->assertSame(0, StockCostLayerEntry::query()->count());

        // بعد العكس يبدأ البيع التالي من الطبقة الاقدم من جديد: 5 × 10 + 1 × 12 = 62.
        $this->assertSame(-62.0, (float) $this->out(6)->sum('total_cost'));
    }

    public function test_reversing_a_purchase_removes_its_layer(): void
    {
        $this->in(5, 10);
        $source = $this->makeProduct();
        $this->ledger->receive($this->movement(['quantity' => 5, 'unit_cost' => 12, 'type' => StockMovementType::RECEIPT, 'source' => $source]));

        $this->ledger->reverse($source, now()->toDateString());

        $this->assertSame(50.0, $this->layersValue());
        $this->assertSame(5.0, (float) ProductCost::query()->where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(50.0, (float) ProductCost::query()->where('product_id', $this->product->id)->value('value'));
    }

    public function test_reversing_a_purchase_whose_layer_was_consumed_is_refused_even_with_stock_left(): void
    {
        $source = $this->makeProduct();
        $this->ledger->receive($this->movement(['quantity' => 5, 'unit_cost' => 10, 'type' => StockMovementType::RECEIPT, 'source' => $source]));
        $this->in(5, 12);
        $this->out(2);

        $this->expectException(DomainException::class);

        $this->ledger->reverse($source, now()->toDateString());
    }

    public function test_a_transfer_carries_each_layer_and_keeps_the_total_value_and_its_age(): void
    {
        $second = $this->makeWarehouse('WH2');
        $source = $this->makeProduct();
        $this->in(5, 10);
        $this->in(5, 12);

        $issued = $this->ledger->issue($this->movement(['quantity' => 7, 'type' => StockMovementType::TRANSFER_OUT, 'source' => $source]));
        $this->ledger->receive($this->movement([
            'warehouse_id' => $second->id,
            'quantity' => 7,
            'unit_cost' => 74 / 7,
            'layers_from' => $issued,
            'type' => StockMovementType::TRANSFER_IN,
            'source' => $source,
        ]));

        // 110 قبل النقل و110 بعده: 36 في الاول و74 في الثاني.
        $this->assertSame(110.0, (float) ProductCost::query()->where('product_id', $this->product->id)->value('value'));
        $this->assertSame(36.0, $this->layersValue($this->warehouse));
        $this->assertSame(74.0, $this->layersValue($second));

        // الاقدم في الثاني بسعر 10 لا بمتوسط النقل: 5 × 10 + 1 × 12 = 62.
        $this->assertSame(-62.0, (float) $this->out(6, $second)->sum('total_cost'));
    }

    public function test_reversing_a_transfer_restores_both_warehouses(): void
    {
        $second = $this->makeWarehouse('WH2');
        $source = $this->makeProduct();
        $this->in(5, 10);
        $this->in(5, 12);

        $issued = $this->ledger->issue($this->movement(['quantity' => 7, 'type' => StockMovementType::TRANSFER_OUT, 'source' => $source]));
        $this->ledger->receive($this->movement(['warehouse_id' => $second->id, 'quantity' => 7, 'unit_cost' => 74 / 7, 'layers_from' => $issued, 'type' => StockMovementType::TRANSFER_IN, 'source' => $source]));

        $this->ledger->reverse($source, now()->toDateString());

        $this->assertSame(110.0, $this->layersValue($this->warehouse));
        $this->assertSame(0.0, $this->layersValue($second));
        $this->assertSame(110.0, (float) ProductCost::query()->where('product_id', $this->product->id)->value('value'));
    }

    public function test_revaluing_reprices_what_remains_of_the_named_receipts_and_reverses_exactly(): void
    {
        $receipt = $this->in(4, 10);
        $this->out(1);
        $source = $this->makeProduct();

        // +12 على طبقة استُهلك ربعها: يُطبَّق ما يخص الباقي 12 × 3 ÷ 4 = 9.
        $applied = $this->ledger->revalue($this->movement([
            'amount' => 12,
            'movements' => [$receipt->id],
            'type' => StockMovementType::INVOICE_ADJUSTMENT,
            'source' => $source,
        ]));

        $this->assertSame(9.0, $applied);
        $layer = StockCostLayer::query()->where('stock_movement_id', $receipt->id)->sole();
        $this->assertSame(39.0, (float) $layer->remaining_value);
        $this->assertSame(13.0, (float) $layer->unit_cost);
        $this->assertSame(39.0, (float) ProductCost::query()->where('product_id', $this->product->id)->value('value'));

        $this->ledger->reverse($source, now()->toDateString());

        $layer->refresh();
        $this->assertSame(30.0, (float) $layer->remaining_value);
        $this->assertSame(10.0, (float) $layer->unit_cost);
        $this->assertSame(30.0, (float) ProductCost::query()->where('product_id', $this->product->id)->value('value'));
    }

    public function test_the_migration_seeds_one_opening_layer_per_product_and_keeps_its_value(): void
    {
        $second = $this->makeWarehouse('WH2');
        $main = $this->makeProduct(['pricing_type' => 'weight']);
        $piece = $this->makeProduct(['pricing_type' => 'weight', 'main_product_id' => $main->id]);

        foreach ([[$this->product, $this->warehouse, 6], [$this->product, $second, 2], [$piece, $this->warehouse, 3]] as [$product, $warehouse, $quantity]) {
            StockBalance::query()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => 0, 'quantity' => $quantity, 'weight' => 0]);
        }

        // متوسط قديم: 8 قطع بقيمة 100.
        ProductCost::query()->create(['product_id' => $this->product->id, 'quantity' => 8, 'value' => 100, 'average_cost' => 12.5]);
        ProductCost::query()->create(['product_id' => $piece->id, 'quantity' => 3, 'value' => 30, 'average_cost' => 10]);

        DB::table(config('accounting.table_prefix').'stock_cost_layers')->delete();
        $migration = require base_path('Modules/Accounting/database/migrations/2026_11_05_100000_create_accounting_stock_cost_layers_tables.php');
        $migration->seedOpeningLayers();

        // 100 × 6 ÷ 8 = 75، والباقي 25.
        $this->assertSame(75.0, $this->layersValue($this->warehouse));
        $this->assertSame(25.0, $this->layersValue($second));
        $this->assertSame(0, StockCostLayer::query()->where('product_id', $piece->id)->count());

        // البيع بعدها يخرج بسعر الافتتاح 12.5.
        $this->assertSame(-25.0, (float) $this->out(2)->sum('total_cost'));
    }

    // ------------------------------------------------------------ helpers

    private function in(float $quantity, float $unitCost, ?Warehouse $warehouse = null): StockMovement
    {
        return $this->ledger->receive($this->movement([
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'type' => StockMovementType::RECEIPT,
        ]));
    }

    private function out(float $quantity, ?Warehouse $warehouse = null)
    {
        return $this->ledger->issue($this->movement([
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'quantity' => $quantity,
            'type' => StockMovementType::DELIVERY,
        ]));
    }

    private function movement(array $data): array
    {
        return array_merge([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => now()->toDateString(),
        ], $data);
    }

    private function layersValue(?Warehouse $warehouse = null): float
    {
        return round((float) StockCostLayer::query()
            ->where('product_id', $this->product->id)
            ->when($warehouse, fn ($query) => $query->where('warehouse_id', $warehouse->id))
            ->sum('remaining_value'), 4);
    }

    private function makeWarehouse(string $code): Warehouse
    {
        $warehouse = Warehouse::create(['code' => $code, 'is_active' => true]);
        $warehouse->translateOrNew('ar')->name = $code;
        $warehouse->save();

        return $warehouse;
    }

    private function makeProduct(array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'slug' => 'layer-'.Str::lower(Str::random(8)),
            'sku' => 'SKU-'.Str::random(8),
            'barcode' => 'L-'.Str::random(6),
            'base_price' => 10,
            'pricing_type' => 'simple',
        ], $attributes));
        $product->save();
        $product->translateOrNew('ar')->name = 'صنف';
        $product->save();

        return $product;
    }
}
