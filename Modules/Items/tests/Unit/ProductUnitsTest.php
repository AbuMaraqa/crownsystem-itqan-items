<?php

namespace Modules\Items\tests\Unit;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductUnit;
use Modules\Items\Entities\Unit;
use Modules\Items\Services\ProductUnitService;
use Modules\Items\Support\UnitConversion;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الوحدات المكافئة: عدد الوحدات الاساسية في الوحدة، وتحويل الكمية والسعر بها.
 */
class ProductUnitsTest extends TestCase
{
    use RefreshDatabase;

    private function unit(string $name): Unit
    {
        $unit = new Unit(['slug' => 'unit-'.Str::lower(Str::random(8)), 'created_by' => User::factory()->create()->id]);
        $unit->save();
        $unit->translateOrNew('ar')->name = $name;
        $unit->save();

        return $unit;
    }

    private function product(?Unit $unit): Product
    {
        $product = new Product(['slug' => 'item-'.Str::lower(Str::random(8)), 'base_price' => 0, 'unit_id' => $unit?->id]);
        $product->save();

        return $product;
    }

    private function service(): ProductUnitService
    {
        return app(ProductUnitService::class);
    }

    /* ---------- التحويل ---------- */

    public function test_a_carton_of_12_at_120_is_12_units_at_10(): void
    {
        $this->assertSame(['quantity' => '12.0000', 'price' => '10.0000'], UnitConversion::toBase(1, 120, 12));
        $this->assertSame(['quantity' => '1.0000', 'price' => '120.0000'], UnitConversion::fromBase(12, 10, 12));
    }

    public function test_conversion_values_are_exact(): void
    {
        $this->assertSame(['quantity' => '7.5000', 'price' => '33.3333'], UnitConversion::toBase('2.5', '100', '3'));
        $this->assertSame(['quantity' => '2.5000', 'price' => '99.9999'], UnitConversion::fromBase('7.5', '33.3333', '3'));
        // 0.1 × 3 لا تعطي 0.30000000000000004.
        $this->assertSame(['quantity' => '0.3000', 'price' => '0.1000'], UnitConversion::toBase('0.1', '0.3', '3'));
        // التقريب نصف لأعلى.
        $this->assertSame('0.6667', UnitConversion::toBase(1, 2, 3)['price']);
        // معامل اصغر من 1 (نصف وحدة اساسية).
        $this->assertSame(['quantity' => '1.0000', 'price' => '20.0000'], UnitConversion::toBase(2, 10, '0.5'));
        // الفاصلة العائمة بالتمثيل العلمي تُقبل.
        $this->assertSame('0.0020', UnitConversion::toBase(200, 1, 1.0E-5)['quantity']);
    }

    #[DataProvider('invalidFactors')]
    public function test_an_invalid_factor_throws_instead_of_pricing_at_zero(mixed $factor): void
    {
        $this->expectException(DomainException::class);

        UnitConversion::toBase(1, 120, $factor);
    }

    #[DataProvider('invalidFactors')]
    public function test_an_invalid_factor_throws_from_base_too(mixed $factor): void
    {
        $this->expectException(DomainException::class);

        UnitConversion::fromBase(12, 10, $factor);
    }

    public static function invalidFactors(): array
    {
        return [
            'zero' => [0],
            'zero string' => ['0.000000'],
            'negative' => [-12],
            'null' => [null],
            'empty' => [''],
            'text' => ['abc'],
            'infinite' => [INF],
        ];
    }

    public function test_a_non_numeric_quantity_or_price_throws(): void
    {
        $this->expectException(DomainException::class);

        UnitConversion::toBase('x', 1, 12);
    }

    /* ---------- الحفظ ---------- */

    public function test_a_unit_is_saved_with_its_factor(): void
    {
        $carton = $this->unit('كرتونة');

        $productUnit = $this->service()->save($this->product($this->unit('قطعة')), ['unit_id' => $carton->id, 'factor' => '12']);

        $this->assertSame('12.000000', $productUnit->refresh()->factor);
        $this->assertSame($carton->id, $productUnit->unit_id);
    }

    public function test_a_duplicate_factor_is_rejected(): void
    {
        $product = $this->product($this->unit('قطعة'));

        $this->service()->save($product, ['unit_id' => $this->unit('كرتونة')->id, 'factor' => 12]);

        try {
            // يساوي 12 بعد التقريب الى دقة العمود.
            $this->service()->save($product, ['unit_id' => $this->unit('علبة')->id, 'factor' => '12.0000001']);
            $this->fail('A duplicate factor was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('factor', $exception->errors());
        }

        $this->assertSame(1, ProductUnit::query()->where('product_id', $product->id)->count());
    }

    public function test_a_duplicate_unit_is_rejected(): void
    {
        $product = $this->product($this->unit('قطعة'));
        $carton = $this->unit('كرتونة');

        $this->service()->save($product, ['unit_id' => $carton->id, 'factor' => 12]);

        try {
            $this->service()->save($product, ['unit_id' => $carton->id, 'factor' => 6]);
            $this->fail('A duplicate unit was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unit_id', $exception->errors());
        }
    }

    public function test_the_same_unit_and_factor_are_allowed_on_another_item(): void
    {
        $piece = $this->unit('قطعة');
        $carton = $this->unit('كرتونة');

        $this->service()->save($this->product($piece), ['unit_id' => $carton->id, 'factor' => 12]);
        $this->service()->save($this->product($piece), ['unit_id' => $carton->id, 'factor' => 12]);

        $this->assertSame(2, ProductUnit::query()->count());
    }

    public function test_editing_a_unit_does_not_collide_with_itself(): void
    {
        $product = $this->product($this->unit('قطعة'));
        $carton = $this->unit('كرتونة');
        $productUnit = $this->service()->save($product, ['unit_id' => $carton->id, 'factor' => 12]);

        $this->service()->save($product, ['unit_id' => $carton->id, 'factor' => 24], $productUnit);

        $this->assertSame('24.000000', $productUnit->refresh()->factor);
    }

    #[DataProvider('rejectedFactors')]
    public function test_a_bad_factor_is_rejected(mixed $factor): void
    {
        $product = $this->product($this->unit('قطعة'));

        try {
            $this->service()->save($product, ['unit_id' => $this->unit('كرتونة')->id, 'factor' => $factor]);
            $this->fail('A bad factor was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('factor', $exception->errors());
        }
    }

    public static function rejectedFactors(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'rounds to zero' => ['0.0000001'],
            'text' => ['abc'],
            'empty' => [''],
            // 1 هو معامل الوحدة الاساسية.
            'base factor' => [1],
        ];
    }

    public function test_the_base_unit_cannot_be_added_again(): void
    {
        $piece = $this->unit('قطعة');

        $this->expectException(ValidationException::class);

        $this->service()->save($this->product($piece), ['unit_id' => $piece->id, 'factor' => 12]);
    }

    public function test_an_item_without_a_base_unit_cannot_have_equivalent_units(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->save($this->product(null), ['unit_id' => $this->unit('كرتونة')->id, 'factor' => 12]);
    }

    public function test_the_database_also_rejects_duplicates_and_non_positive_factors(): void
    {
        $product = $this->product($this->unit('قطعة'));
        $carton = $this->unit('كرتونة');
        $box = $this->unit('علبة');

        $this->service()->save($product, ['unit_id' => $carton->id, 'factor' => 12]);

        foreach ([
            'duplicate factor' => ['unit_id' => $box->id, 'factor' => 12],
            'duplicate unit' => ['unit_id' => $carton->id, 'factor' => 6],
            'zero factor' => ['unit_id' => $box->id, 'factor' => 0],
        ] as $case => $row) {
            try {
                DB::transaction(fn () => DB::table('items_product_units')->insert($row + ['product_id' => $product->id]));
                $this->fail("The database accepted: {$case}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_unit_used_as_an_equivalent_unit_counts_as_used(): void
    {
        $piece = $this->unit('قطعة');
        $carton = $this->unit('كرتونة');
        $product = $this->product($piece);

        $this->assertFalse($carton->isUsed());

        $this->service()->save($product, ['unit_id' => $carton->id, 'factor' => 12]);

        $this->assertTrue($carton->isUsed());
        $this->assertTrue($piece->isUsed());
    }
}
