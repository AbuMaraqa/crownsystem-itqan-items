<?php

namespace Modules\Items\tests\Unit;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Core\Entities\User;
use Modules\Items\Entities\PriceList;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductPrice;
use Modules\Items\Entities\ProductUnit;
use Modules\Items\Entities\Unit;
use Modules\Items\Services\ProductPriceService;
use Modules\Items\Services\ProductUnitService;
use Modules\Items\Support\PriceResolver;
use Tests\TestCase;

/**
 * قوائم الاسعار: اختيار السعر، والمعادلة بمستوى واحد، والحد الادنى، وفرادة الصف.
 */
class PriceListsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = $this->product();
    }

    private function product(): Product
    {
        $piece = new Unit(['slug' => 'unit-'.Str::lower(Str::random(8)), 'created_by' => User::factory()->create()->id]);
        $piece->save();

        $product = new Product(['slug' => 'item-'.Str::lower(Str::random(8)), 'base_price' => 0, 'unit_id' => $piece->id]);
        $product->save();

        return $product;
    }

    private function list(string $name = 'قائمة', bool $sameBuySell = false): PriceList
    {
        return PriceList::create(['name' => $name, 'same_buy_sell' => $sameBuySell]);
    }

    private function carton(): ProductUnit
    {
        $unit = new Unit(['slug' => 'unit-'.Str::lower(Str::random(8)), 'created_by' => User::factory()->create()->id]);
        $unit->save();

        return app(ProductUnitService::class)->save($this->product, ['unit_id' => $unit->id, 'factor' => 12]);
    }

    private function price(PriceList $list, array $data, ?ProductUnit $unit = null): ProductPrice
    {
        return app(ProductPriceService::class)->save($this->product, $list, $unit, $data);
    }

    public function test_no_row_means_null(): void
    {
        $this->assertNull(PriceResolver::resolve($this->product, $this->list()));
    }

    public function test_it_returns_the_row_price_for_the_requested_side(): void
    {
        $list = $this->list();
        $this->price($list, ['sell_price' => 15, 'buy_price' => 9.5]);

        $this->assertSame('15.0000', PriceResolver::resolve($this->product, $list, null, 'sell'));
        $this->assertSame('9.5000', PriceResolver::resolve($this->product, $list, side: 'buy'));
        $this->assertSame('15.0000', PriceResolver::resolve($this->product, $list));
    }

    public function test_an_unknown_side_is_an_error(): void
    {
        $list = $this->list();
        $this->price($list, ['sell_price' => 15]);

        $this->expectException(InvalidArgumentException::class);

        PriceResolver::resolve($this->product, $list, side: 'rent');
    }

    public function test_prices_are_per_unit(): void
    {
        $list = $this->list();
        $carton = $this->carton();

        $this->price($list, ['sell_price' => 10]);
        $this->price($list, ['sell_price' => 115], $carton);

        $this->assertSame('10.0000', PriceResolver::resolve($this->product, $list));
        $this->assertSame('115.0000', PriceResolver::resolve($this->product, $list, $carton));
    }

    public function test_the_equation_multiplies_the_source_price_on_the_same_side(): void
    {
        $base = $this->list('الاساس');
        $derived = $this->list('المشتقة');

        $this->price($base, ['sell_price' => 100, 'buy_price' => 80]);
        $this->price($derived, ['sell_price' => 1, 'buy_price' => 2, 'source_price_list_id' => $base->id, 'source_factor' => '1.5']);

        $this->assertSame('150.0000', PriceResolver::resolve($this->product, $derived, side: 'sell'));
        $this->assertSame('120.0000', PriceResolver::resolve($this->product, $derived, side: 'buy'));
    }

    public function test_the_equation_uses_the_same_unit_in_the_source_list(): void
    {
        $base = $this->list();
        $derived = $this->list();
        $carton = $this->carton();

        $this->price($base, ['sell_price' => 10]);
        $this->price($base, ['sell_price' => 100], $carton);
        $this->price($derived, ['source_price_list_id' => $base->id, 'source_factor' => 2], $carton);

        $this->assertSame('200.0000', PriceResolver::resolve($this->product, $derived, $carton));
        $this->assertNull(PriceResolver::resolve($this->product, $derived));
    }

    public function test_the_equation_rounds_to_four_decimals(): void
    {
        $base = $this->list();
        $derived = $this->list();

        $this->price($base, ['sell_price' => '10.5555']);
        $this->price($derived, ['source_price_list_id' => $base->id, 'source_factor' => '1.333333']);

        // 10.5555 × 1.333333 = 14.07399...
        $this->assertSame('14.0740', PriceResolver::resolve($this->product, $derived));
    }

    public function test_a_missing_source_row_falls_back_to_the_row_price(): void
    {
        $emptySource = $this->list();
        $derived = $this->list();

        $this->price($derived, ['sell_price' => 33, 'source_price_list_id' => $emptySource->id, 'source_factor' => 2]);

        $this->assertSame('33.0000', PriceResolver::resolve($this->product, $derived));
    }

    public function test_a_deleted_source_list_leaves_the_row_price(): void
    {
        $source = $this->list();
        $derived = $this->list();

        $this->price($derived, ['sell_price' => 33, 'source_price_list_id' => $source->id, 'source_factor' => 2]);
        DB::table('items_price_lists')->where('id', $source->id)->delete();

        $row = ProductPrice::query()->where('price_list_id', $derived->id)->first();

        $this->assertNull($row->source_price_list_id);
        $this->assertSame('33.0000', PriceResolver::resolve($this->product, $derived));
    }

    public function test_the_equation_goes_one_level_only(): void
    {
        $c = $this->list('ج');
        $b = $this->list('ب');
        $a = $this->list('أ');

        $this->price($c, ['sell_price' => 100]);
        $this->price($b, ['sell_price' => 50, 'source_price_list_id' => $c->id, 'source_factor' => 3]);
        $this->price($a, ['sell_price' => 1, 'source_price_list_id' => $b->id, 'source_factor' => 2]);

        // ب تُحسب من ج: 300. لكن أ تأخذ سعر ب المحفوظ (50) × 2، لا 300 × 2.
        $this->assertSame('300.0000', PriceResolver::resolve($this->product, $b));
        $this->assertSame('100.0000', PriceResolver::resolve($this->product, $a));
    }

    public function test_cycles_do_not_recurse(): void
    {
        $a = $this->list();
        $b = $this->list();

        $this->price($a, ['sell_price' => 10, 'source_price_list_id' => $b->id, 'source_factor' => 2]);
        $this->price($b, ['sell_price' => 20, 'source_price_list_id' => $a->id, 'source_factor' => 3]);

        $this->assertSame('40.0000', PriceResolver::resolve($this->product, $a));
        $this->assertSame('30.0000', PriceResolver::resolve($this->product, $b));
    }

    public function test_a_row_pointing_at_its_own_list_returns_its_own_price(): void
    {
        $list = $this->list();
        $row = $this->price($list, ['sell_price' => 10]);

        // قيمة قديمة او مكتوبة مباشرة في الجدول: الخدمة ترفضها.
        DB::table('items_product_prices')->where('id', $row->id)->update(['source_price_list_id' => $list->id, 'source_factor' => 2]);

        $this->assertSame('10.0000', PriceResolver::resolve($this->product, $list));
    }

    public function test_the_service_rejects_a_bad_equation(): void
    {
        $list = $this->list();
        $source = $this->list();

        $cases = [
            'itself' => [['sell_price' => 1, 'source_price_list_id' => $list->id, 'source_factor' => 2], 'source_price_list_id'],
            'zero factor' => [['sell_price' => 1, 'source_price_list_id' => $source->id, 'source_factor' => 0], 'source_factor'],
            'no factor' => [['sell_price' => 1, 'source_price_list_id' => $source->id], 'source_factor'],
            'negative price' => [['sell_price' => -1], 'sell_price'],
            'text price' => [['buy_price' => 'x'], 'buy_price'],
        ];

        foreach ($cases as $name => [$data, $field]) {
            try {
                $this->price($list, $data);
                $this->fail("Accepted: {$name}");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors(), $name);
            }
        }
    }

    public function test_a_list_with_same_buy_and_sell_stores_buy_equal_to_sell(): void
    {
        $list = $this->list('موحدة', true);

        $row = $this->price($list, ['sell_price' => 25, 'buy_price' => 7]);

        $this->assertSame('25.0000', $row->refresh()->buy_price);
        $this->assertSame('25.0000', PriceResolver::resolve($this->product, $list, side: 'buy'));
    }

    public function test_turning_on_same_buy_and_sell_updates_saved_prices(): void
    {
        $list = $this->list();
        $row = $this->price($list, ['sell_price' => 25, 'buy_price' => 7]);

        $list->update(['same_buy_sell' => true]);

        $this->assertSame('25.0000', $row->refresh()->buy_price);
    }

    public function test_only_one_row_is_allowed_for_a_null_unit(): void
    {
        $list = $this->list();
        $this->price($list, ['sell_price' => 10]);

        $this->expectException(QueryException::class);

        DB::table('items_product_prices')->insert([
            'price_list_id' => $list->id,
            'product_id' => $this->product->id,
            'product_unit_id' => null,
            'sell_price' => 20,
            'buy_price' => 0,
        ]);
    }

    public function test_only_one_row_is_allowed_for_a_unit(): void
    {
        $list = $this->list();
        $carton = $this->carton();
        $this->price($list, ['sell_price' => 10], $carton);

        $this->expectException(QueryException::class);

        DB::table('items_product_prices')->insert([
            'price_list_id' => $list->id,
            'product_id' => $this->product->id,
            'product_unit_id' => $carton->id,
            'sell_price' => 20,
            'buy_price' => 0,
        ]);
    }

    public function test_the_service_updates_the_null_unit_row_instead_of_adding_one(): void
    {
        $list = $this->list();
        $carton = $this->carton();

        $this->price($list, ['sell_price' => 10]);
        $this->price($list, ['sell_price' => 11]);
        $this->price($list, ['sell_price' => 100], $carton);

        $this->assertSame(2, ProductPrice::query()->where('price_list_id', $list->id)->count());
        $this->assertSame('11.0000', PriceResolver::resolve($this->product, $list));
    }

    public function test_deleting_a_unit_removes_its_prices(): void
    {
        $list = $this->list();
        $carton = $this->carton();
        $this->price($list, ['sell_price' => 100], $carton);

        app(ProductUnitService::class)->delete($carton);

        $this->assertSame(0, ProductPrice::query()->where('price_list_id', $list->id)->count());
    }

    public function test_an_emptied_row_is_deleted(): void
    {
        $list = $this->list();
        $this->price($list, ['sell_price' => 10]);

        $this->assertNull(app(ProductPriceService::class)->save($this->product, $list, null, ['sell_price' => '', 'buy_price' => '', 'source_price_list_id' => null]));
        $this->assertNull(PriceResolver::resolve($this->product, $list));
    }

    public function test_a_unit_of_another_item_is_rejected(): void
    {
        $carton = $this->carton();
        $other = $this->product();

        $this->expectException(ValidationException::class);

        app(ProductPriceService::class)->save($other, $this->list(), $carton, ['sell_price' => 1]);
    }

    public function test_a_list_with_prices_cannot_be_deleted(): void
    {
        $list = $this->list();
        $derived = $this->list();
        $this->price($derived, ['sell_price' => 1, 'source_price_list_id' => $list->id, 'source_factor' => 2]);

        $this->assertNotNull($list->deletionBlocker());
        $this->assertNotNull($derived->deletionBlocker());

        $this->expectException(DomainException::class);

        $derived->delete();
    }

    /* ---------- الحد الادنى ---------- */

    public function test_a_price_below_the_minimum_returns_the_minimum(): void
    {
        $minimum = $this->list();
        $this->price($minimum, ['sell_price' => 50]);

        $this->assertSame('50.0000', PriceResolver::minimumViolation($this->product, null, $minimum, 49.99));
        $this->assertSame('50.0000', PriceResolver::minimumViolation($this->product, null, $minimum, '10'));
    }

    public function test_a_price_at_or_above_the_minimum_is_fine(): void
    {
        $minimum = $this->list();
        $this->price($minimum, ['sell_price' => 50]);

        $this->assertNull(PriceResolver::minimumViolation($this->product, null, $minimum, 50));
        $this->assertNull(PriceResolver::minimumViolation($this->product, null, $minimum, 60));
    }

    public function test_a_zero_price_or_a_zero_or_missing_minimum_is_not_a_violation(): void
    {
        $minimum = $this->list();
        $empty = $this->list();
        $zero = $this->list();

        $this->price($minimum, ['sell_price' => 50]);
        $this->price($zero, ['sell_price' => 0]);

        $this->assertNull(PriceResolver::minimumViolation($this->product, null, $minimum, 0));
        $this->assertNull(PriceResolver::minimumViolation($this->product, null, $empty, 1));
        $this->assertNull(PriceResolver::minimumViolation($this->product, null, $zero, 1));
    }

    public function test_the_minimum_is_per_unit_and_follows_the_equation(): void
    {
        $base = $this->list();
        $minimum = $this->list();
        $carton = $this->carton();

        $this->price($base, ['sell_price' => 100], $carton);
        $this->price($minimum, ['source_price_list_id' => $base->id, 'source_factor' => '0.9'], $carton);

        $this->assertSame('90.0000', PriceResolver::minimumViolation($this->product, $carton, $minimum, 80));
        $this->assertNull(PriceResolver::minimumViolation($this->product, null, $minimum, 80));
    }
}
