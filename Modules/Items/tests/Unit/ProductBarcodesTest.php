<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\Unit;
use Modules\Items\Services\ProductBarcodeService;
use Modules\Items\Services\ProductUnitService;
use Modules\Items\Support\BarcodeResolver;
use Modules\Items\Support\ProductSearch;
use Modules\Items\Support\Search\ItemsSearchIndex;
use Modules\Items\Support\Search\SearchCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الباركودات الاضافية وقراءتها: المطابقة التامة قبل البادئة، واطول بادئة تغلب،
 * والمحذوف لا يطابق، والباركود لا يتكرر بين الجدولين، ويُعثر عليه في البحث.
 */
class ProductBarcodesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SearchCapabilities::forget();
        SearchCapabilities::withoutFullText();
    }

    private function product(array $attributes = [], string $name = 'صنف باركود'): Product
    {
        $product = new Product(array_merge([
            'slug' => 'item-'.Str::lower(Str::random(8)),
            'base_price' => 0,
        ], $attributes));
        $product->save();
        $product->translateOrNew('ar')->name = $name;
        $product->save();

        return $product;
    }

    private function unit(string $name): Unit
    {
        $unit = new Unit(['slug' => 'unit-'.Str::lower(Str::random(8)), 'created_by' => User::factory()->create()->id]);
        $unit->save();
        $unit->translateOrNew('ar')->name = $name;
        $unit->save();

        return $unit;
    }

    private function service(): ProductBarcodeService
    {
        return app(ProductBarcodeService::class);
    }

    /* ---------- الحفظ ---------- */

    public function test_a_leading_star_is_stripped_and_means_prefix(): void
    {
        $row = $this->service()->save($this->product(), ['barcode' => ' *12345 ']);

        $this->assertSame('12345', $row->barcode);
        $this->assertTrue($row->is_prefix);
        $this->assertSame('1.000000', $row->pack_quantity);
    }

    #[DataProvider('invalidBarcodes')]
    public function test_an_invalid_barcode_is_rejected(array $data, string $field): void
    {
        try {
            $this->service()->save($this->product(), $data);
            $this->fail('An invalid barcode was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function invalidBarcodes(): array
    {
        return [
            'empty' => [['barcode' => '  '], 'barcode'],
            'only a star' => [['barcode' => '*'], 'barcode'],
            'zero pack' => [['barcode' => 'A1', 'pack_quantity' => 0], 'pack_quantity'],
            'negative pack' => [['barcode' => 'A1', 'pack_quantity' => -3], 'pack_quantity'],
            'text pack' => [['barcode' => 'A1', 'pack_quantity' => 'x'], 'pack_quantity'],
        ];
    }

    public function test_the_same_barcode_is_allowed_on_another_item(): void
    {
        $first = $this->service()->save($this->product(), ['barcode' => 'DUP']);
        $second = $this->service()->save($this->product(), ['barcode' => '*DUP']);

        $this->assertTrue($first->exists);
        $this->assertTrue($second->exists);
        $this->assertSame('DUP', $second->barcode);
    }

    public function test_the_database_allows_the_same_barcode_on_another_item(): void
    {
        $this->service()->save($this->product(), ['barcode' => 'DUP']);

        $this->assertTrue(DB::table('items_product_barcodes')->insert(['product_id' => $this->product()->id, 'barcode' => 'DUP']));
        $this->assertSame(2, DB::table('items_product_barcodes')->where('barcode', 'DUP')->count());
    }

    public function test_a_barcode_cannot_repeat_on_the_same_item(): void
    {
        $product = $this->product();
        $this->service()->save($product, ['barcode' => 'DUP']);

        try {
            $this->service()->save($product, ['barcode' => '*DUP']);
            $this->fail('A barcode was repeated on the same item.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('barcode', $exception->errors());
        }
    }

    public function test_the_database_rejects_a_repeat_on_the_same_item(): void
    {
        $product = $this->product();
        $this->service()->save($product, ['barcode' => 'DUP']);

        $this->expectException(QueryException::class);

        DB::table('items_product_barcodes')->insert(['product_id' => $product->id, 'barcode' => 'DUP']);
    }

    public function test_a_barcode_equal_to_another_items_main_barcode_is_allowed(): void
    {
        $this->product(['barcode' => 'MAIN-1']);

        $row = $this->service()->save($this->product(), ['barcode' => 'MAIN-1']);

        $this->assertTrue($row->exists);
    }

    public function test_other_items_with_the_barcode_are_counted_for_the_notice(): void
    {
        $product = $this->product(['barcode' => 'COUNT-1']);
        $this->assertSame(0, $this->service()->otherItemsCount($product, 'COUNT-1'));

        $this->product(['barcode' => 'COUNT-1']);
        $this->service()->save($this->product(), ['barcode' => 'COUNT-1']);
        $this->product(['barcode' => 'COUNT-1'])->delete();

        // المحذوف لا يُعدّ، والصنف نفسه لا يُعدّ.
        $this->assertSame(2, $this->service()->otherItemsCount($product, 'COUNT-1'));
        $this->assertSame(2, $this->service()->otherItemsCount($product, '*COUNT-1'));
    }

    public function test_a_barcode_equal_to_the_items_own_main_barcode_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->save($this->product(['barcode' => 'OWN-1']), ['barcode' => 'OWN-1']);
    }

    public function test_the_main_barcode_of_a_deleted_item_does_not_block(): void
    {
        $this->product(['barcode' => 'GONE-1'])->delete();

        $row = $this->service()->save($this->product(), ['barcode' => 'GONE-1']);

        $this->assertTrue($row->exists);
    }

    public function test_editing_a_barcode_does_not_collide_with_itself(): void
    {
        $product = $this->product();
        $row = $this->service()->save($product, ['barcode' => 'SELF-1']);

        $this->service()->save($product, ['barcode' => 'SELF-1', 'pack_quantity' => 6], $row);

        $this->assertSame('6.000000', $row->refresh()->pack_quantity);
    }

    public function test_the_unit_must_belong_to_the_item(): void
    {
        $owner = $this->product(['unit_id' => $this->unit('قطعة')->id]);
        $carton = app(ProductUnitService::class)->save($owner, ['unit_id' => $this->unit('كرتونة')->id, 'factor' => 12]);

        $this->expectException(ValidationException::class);

        $this->service()->save($this->product(), ['barcode' => 'X1', 'product_unit_id' => $carton->id]);
    }

    /* ---------- القراءة ---------- */

    public function test_nothing_matches_returns_null(): void
    {
        $this->assertNull(BarcodeResolver::resolve('NO-SUCH-CODE'));
        $this->assertNull(BarcodeResolver::resolve('   '));
        $this->assertSame([], BarcodeResolver::resolveAll('NO-SUCH-CODE'));
        $this->assertSame([], BarcodeResolver::resolveAll('   '));
    }

    public function test_a_code_on_many_items_is_never_picked_silently(): void
    {
        $firstMain = $this->product(['barcode' => 'MANY-1']);
        $secondMain = $this->product(['barcode' => 'MANY-1']);
        $additional = $this->product();
        $this->service()->save($additional, ['barcode' => 'MANY-1', 'pack_quantity' => 6]);

        $this->assertNull(BarcodeResolver::resolve('MANY-1'));

        $matches = BarcodeResolver::resolveAll('MANY-1');

        $this->assertSame(
            [$firstMain->id, $secondMain->id, $additional->id],
            array_map(fn ($match) => $match->product->id, $matches)
        );
        $this->assertSame(['primary', 'primary', 'exact'], array_map(fn ($match) => $match->matchedBy, $matches));
        $this->assertSame('6.000000', $matches[2]->packQuantity);
    }

    public function test_two_barcodes_of_one_item_still_count_as_one_item(): void
    {
        $product = $this->product(['barcode' => 'ONE-11']);
        $this->service()->save($product, ['barcode' => '*ONE-']);

        $this->assertCount(2, BarcodeResolver::resolveAll('ONE-11'));

        // الاعلى اولوية هو الذي يعود.
        $resolved = BarcodeResolver::resolve('ONE-11');

        $this->assertTrue($resolved->product->is($product));
        $this->assertSame('primary', $resolved->matchedBy);
    }

    public function test_a_deleted_item_does_not_make_a_code_ambiguous(): void
    {
        $live = $this->product(['barcode' => 'LIVE-1']);
        $this->product(['barcode' => 'LIVE-1'])->delete();

        $this->assertTrue(BarcodeResolver::resolve('LIVE-1')->product->is($live));
        $this->assertCount(1, BarcodeResolver::resolveAll('LIVE-1'));
    }

    public function test_the_main_barcode_resolves_to_the_base_unit_at_quantity_one(): void
    {
        $piece = $this->unit('قطعة');
        $product = $this->product(['barcode' => 'MAIN-2', 'unit_id' => $piece->id]);

        $resolved = BarcodeResolver::resolve(' MAIN-2 ');

        $this->assertTrue($resolved->product->is($product));
        $this->assertTrue($resolved->unit->is($piece));
        $this->assertNull($resolved->productUnit);
        $this->assertSame('1.000000', $resolved->packQuantity);
        $this->assertSame('primary', $resolved->matchedBy);
    }

    public function test_an_additional_barcode_resolves_to_its_unit_and_pack_quantity(): void
    {
        $piece = $this->unit('قطعة');
        $cartonUnit = $this->unit('كرتونة');
        $product = $this->product(['unit_id' => $piece->id]);
        $carton = app(ProductUnitService::class)->save($product, ['unit_id' => $cartonUnit->id, 'factor' => 12]);

        $this->service()->save($product, ['barcode' => 'CTN-1', 'product_unit_id' => $carton->id, 'pack_quantity' => 2]);
        $this->service()->save($product, ['barcode' => 'PACK-1', 'pack_quantity' => 6]);

        $resolved = BarcodeResolver::resolve('CTN-1');

        $this->assertTrue($resolved->product->is($product));
        $this->assertTrue($resolved->unit->is($cartonUnit));
        $this->assertTrue($resolved->productUnit->is($carton));
        $this->assertSame('2.000000', $resolved->packQuantity);
        $this->assertSame('exact', $resolved->matchedBy);

        // بلا وحدة: الوحدة الاساسية.
        $this->assertTrue(BarcodeResolver::resolve('PACK-1')->unit->is($piece));
    }

    public function test_all_matches_come_in_priority_order_main_then_additional_then_prefix(): void
    {
        $main = $this->product(['barcode' => '7290001']);
        $additional = $this->product();
        $prefix = $this->product();

        $this->service()->save($prefix, ['barcode' => '*729']);
        $this->service()->save($additional, ['barcode' => '7290001']);

        $matches = BarcodeResolver::resolveAll('7290001');

        $this->assertSame([$main->id, $additional->id, $prefix->id], array_map(fn ($match) => $match->product->id, $matches));
        $this->assertSame(['primary', 'exact', 'prefix'], array_map(fn ($match) => $match->matchedBy, $matches));

        // اكثر من صنف يطابق، ولو بمستويات مختلفة: لا يُختار احدها بصمت.
        $this->assertNull(BarcodeResolver::resolve('7290001'));
    }

    public function test_a_single_item_resolves_at_each_level(): void
    {
        $this->product(['barcode' => 'L-MAIN']);
        $this->service()->save($this->product(), ['barcode' => 'L-EXACT']);
        $this->service()->save($this->product(), ['barcode' => '*L-PRE']);

        $this->assertSame('primary', BarcodeResolver::resolve('L-MAIN')->matchedBy);
        $this->assertSame('exact', BarcodeResolver::resolve('L-EXACT')->matchedBy);
        $this->assertSame('prefix', BarcodeResolver::resolve('L-PRE-55')->matchedBy);
    }

    public function test_prefix_matches_come_longest_first(): void
    {
        $short = $this->product();
        $long = $this->product();
        $notPrefix = $this->product();

        $this->service()->save($short, ['barcode' => '*123']);
        $this->service()->save($long, ['barcode' => '*12345']);
        // ليس بادئة، فلا يطابق ما يبدأ به.
        $this->service()->save($notPrefix, ['barcode' => '123456']);

        $this->assertSame(
            [$long->id, $short->id],
            array_map(fn ($match) => $match->product->id, BarcodeResolver::resolveAll('1234567'))
        );

        // بادئتان لصنفين: لا يُختار احدهما بصمت. وبادئة واحدة تطابق.
        $this->assertNull(BarcodeResolver::resolve('1234567'));
        $this->assertTrue(BarcodeResolver::resolve('12399')->product->is($short));
        $this->assertNull(BarcodeResolver::resolve('12'));
    }

    public function test_an_exact_row_that_is_also_a_prefix_is_listed_once(): void
    {
        $product = $this->product();
        $this->service()->save($product, ['barcode' => '*ROW-1']);

        $matches = BarcodeResolver::resolveAll('ROW-1');

        $this->assertCount(1, $matches);
        $this->assertSame('exact', $matches[0]->matchedBy);
    }

    public function test_a_prefix_does_not_treat_like_wildcards_as_wildcards(): void
    {
        $this->service()->save($this->product(), ['barcode' => '*AB%']);

        $this->assertNull(BarcodeResolver::resolve('ABCD'));
        $this->assertNotNull(BarcodeResolver::resolve('AB%CD'));
    }

    public function test_soft_deleted_items_do_not_match(): void
    {
        $this->product(['barcode' => 'DEL-MAIN'])->delete();

        $exact = $this->product();
        $this->service()->save($exact, ['barcode' => 'DEL-EXACT']);
        $exact->delete();

        $prefix = $this->product();
        $this->service()->save($prefix, ['barcode' => '*DEL-PRE']);
        $prefix->delete();

        $this->assertNull(BarcodeResolver::resolve('DEL-MAIN'));
        $this->assertNull(BarcodeResolver::resolve('DEL-EXACT'));
        $this->assertNull(BarcodeResolver::resolve('DEL-PRE-99'));
    }

    public function test_a_deleted_long_prefix_gives_way_to_a_live_shorter_one(): void
    {
        $short = $this->product();
        $long = $this->product();
        $this->service()->save($short, ['barcode' => '*55']);
        $this->service()->save($long, ['barcode' => '*5566']);
        $long->delete();

        $this->assertTrue(BarcodeResolver::resolve('556677')->product->is($short));
    }

    /* ---------- البحث ---------- */

    public function test_an_additional_barcode_is_found_by_product_search(): void
    {
        $product = $this->product([], 'مفصلة باب');
        $this->service()->save($product, ['barcode' => '8801234']);
        app(ItemsSearchIndex::class)->flush();

        $this->assertSame([$product->id], ProductSearch::find('8801234')->ids);
        // بداية الباركود ايضاً.
        $this->assertContains($product->id, ProductSearch::find('88012')->ids);
        $this->assertSame([$product->id], ProductSearch::apply(Product::query(), '8801234')->pluck('id')->all());
    }

    public function test_the_additional_barcode_is_in_the_search_document_and_leaves_with_it(): void
    {
        $product = $this->product([], 'مفصلة باب');
        $row = $this->service()->save($product, ['barcode' => 'K-4593']);
        app(ItemsSearchIndex::class)->flush();

        $document = fn () => DB::table(SearchCapabilities::productTable())->where('product_id', $product->id)->value(SearchCapabilities::DOCUMENT);

        $this->assertStringContainsString(' k4593 ', $document());

        $this->service()->delete($row);
        app(ItemsSearchIndex::class)->flush();

        $this->assertStringNotContainsString('k4593', $document());
    }

    public function test_a_rebuild_includes_additional_barcodes(): void
    {
        $product = $this->product();
        DB::table('items_product_barcodes')->insert(['product_id' => $product->id, 'barcode' => 'REBUILT-77']);

        app(ItemsSearchIndex::class)->rebuild();

        $this->assertStringContainsString(
            ' rebuilt ',
            (string) DB::table(SearchCapabilities::productTable())->where('product_id', $product->id)->value(SearchCapabilities::DOCUMENT)
        );
        $this->assertContains($product->id, ProductSearch::find('REBUILT-77')->ids);
    }

    public function test_an_exact_additional_barcode_outranks_a_main_barcode_that_only_starts_with_it(): void
    {
        $startsWith = $this->product(['barcode' => '4455667788'], 'صنف اول');
        $exact = $this->product([], 'صنف ثان');
        $this->service()->save($exact, ['barcode' => '445566']);
        app(ItemsSearchIndex::class)->flush();

        $this->assertSame([$exact->id, $startsWith->id], ProductSearch::find('445566')->ids);
    }

    public function test_every_item_with_a_shared_barcode_is_found_by_product_search(): void
    {
        $main = $this->product(['barcode' => '6600112'], 'مقطع ابيض');
        $additional = $this->product([], 'مقطع اسود');
        $this->service()->save($additional, ['barcode' => '6600112']);
        app(ItemsSearchIndex::class)->flush();

        $found = ProductSearch::find('6600112')->ids;

        $this->assertContains($main->id, $found);
        $this->assertContains($additional->id, $found);
        $this->assertEqualsCanonicalizing(
            [$main->id, $additional->id],
            ProductSearch::apply(Product::query(), '6600112')->pluck('id')->all()
        );
    }

    public function test_a_deleted_item_is_not_found_by_its_additional_barcode(): void
    {
        $product = $this->product();
        $this->service()->save($product, ['barcode' => 'HIDDEN-1']);
        app(ItemsSearchIndex::class)->flush();

        $product->delete();
        app(ItemsSearchIndex::class)->flush();

        $this->assertSame([], ProductSearch::find('HIDDEN-1')->ids);
        $this->assertSame([], ProductSearch::apply(Product::query(), 'HIDDEN-1')->pluck('id')->all());
    }
}
