<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Items\Entities\Product;
use Modules\Items\Services\LegacyItemsImporter;
use Modules\Items\Support\Search\SearchCapabilities;
use Tests\TestCase;

/**
 * صفوف items من ملفات إتقان تأتي بقيم مكتوبة (double وint وNULL)، لا نصوصاً كملف SQL.
 * يجب ان ينتج النقل منها الصنف نفسه الذي ينتجه ملف SQL.
 */
class ItqanRowsImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, string|null>
     */
    protected $connectionsToTransact = [];

    private const SKUS = ['QC1', 'QA1', 'QA1001', 'QS1'];

    protected function setUp(): void
    {
        parent::setUp();

        SearchCapabilities::forget();
    }

    protected function tearDown(): void
    {
        DB::table('items_products')->whereIn('sku', self::SKUS)->delete();
        DB::table('items_categories')->whereIn('code', self::SKUS)->delete();
        DB::table('items_units')->where('slug', 'like', 'legacy-unit-%')->delete();
        SearchCapabilities::forget();

        parent::tearDown();
    }

    public function test_typed_values_from_itqan_files_import_like_the_sql_dump(): void
    {
        app(LegacyItemsImporter::class)->importRows([
            $this->row(['ItemId' => 'QC1', 'ItemString' => 'تصنيف', 'ItemType' => 'c_cont', 'ItemParent' => '0']),
            $this->row([
                'ItemId' => 'QS1', 'ItemString' => 'ماتور ميلانو', 'ItemType' => 'a_storePicture', 'ItemParent' => 'QC1',
                'ItemCost' => 21.63, 'ItemMax' => 40, 'ItemMin1' => 3, 'ItemMin2' => 0, 'ItemDefStore' => 1,
                'Barcode' => '111█222', 'itemunit' => 'قطعة',
            ]),
            $this->row([
                'ItemId' => 'QA1', 'ItemString' => 'المنيوم لون ايرون', 'ItemType' => 'a_al', 'ItemParent' => 'QC1',
                'ItemCost' => 17.4, 'isActive' => 'N',
            ]),
            $this->row(['ItemId' => 'QA1001', 'ItemString' => 'مقطع', 'ItemType' => 'b_al', 'ItemParent' => 'QA1', 'Prop1' => '0.725', 'Prop2' => '6.00']),
        ], 'test');

        $store = Product::query()->with('productBarcodes')->where('sku', 'QS1')->firstOrFail();

        $this->assertSame('21.63', number_format((float) $store->cost_price, 2, '.', ''));
        $this->assertSame(40, (int) $store->max_limit);
        $this->assertSame(3, (int) $store->min_limit_1);
        $this->assertNull($store->min_limit_2);
        $this->assertSame('111', $store->barcode);
        $this->assertSame(['222'], $store->productBarcodes->pluck('barcode')->all());
        $this->assertSame(1, $store->meta_data['default_store']);
        $this->assertSame('simple', $store->pricing_type);

        $main = Product::query()->where('sku', 'QA1')->firstOrFail();
        $this->assertSame('weight', $main->pricing_type);
        $this->assertSame(0, (int) $main->is_active);
        $this->assertSame('17.40', number_format((float) $main->cost_price, 2, '.', ''));

        $piece = Product::query()->where('sku', 'QA1001')->firstOrFail();
        $this->assertSame('0.725', rtrim(rtrim((string) $piece->weight_per_meter, '0'), '.') ?: '0');
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function row(array $values): array
    {
        return array_merge(array_fill_keys(LegacyItemsImporter::LEGACY_COLUMNS, null), ['isActive' => 'Y', 'ChRange' => 3], $values);
    }
}
