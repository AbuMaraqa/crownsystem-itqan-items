<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Itqan\LegacyLinks;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\CategoryFinish;
use Modules\Items\Entities\Finish;
use Modules\Items\Entities\Product;
use Modules\Items\Services\Itqan\CatalogImporter;
use Modules\Items\Services\LegacyItemsImporter;
use Modules\Items\Support\Itqan\CategoryMapEntry;
use Tests\TestCase;

/**
 * المستورد الجديد: كل مقطع مرة واحدة (صنف اب) وألوانه أبناء، وسعر الكيلو على
 * (التصنيف × اللون). التصميم: docs/superpowers/specs/2026-11-21-items-catalog-redesign-design.md §6.2.
 */
class CatalogImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = sys_get_temp_dir().DIRECTORY_SEPARATOR.'catalog-import-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->reports.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->reports);

        parent::tearDown();
    }

    public function test_same_profile_in_two_colours_becomes_one_parent_with_two_children(): void
    {
        $this->import([
            $this->leaf('00009001', 'ثابت مجرين W45', '00009', weight: '1.267', barcode: '809712'),
            $this->leaf('00001001', 'ثابت مجرين W45', '00001', weight: '1.267', barcode: '809712'),
        ], allFinishes: true);

        $parent = Product::query()->whereNull('parent_id')->sole();
        $children = Product::query()->where('parent_id', $parent->id)->with('finish')->orderBy('sku')->get();

        $this->assertSame(['00001001', '00009001'], $children->pluck('sku')->all());
        $this->assertSame(['ابيض', 'بدون دهان'], $children->map(fn (Product $child) => $child->finish->name)->all());
        $this->assertSame('809712', $parent->barcode);
        $this->assertSame('weight', $parent->pricing_type);
    }

    public function test_unused_leaf_links_to_parent_without_child(): void
    {
        $this->import([
            $this->leaf('00009001', 'ثابت مجرين W45', '00009', weight: '1.267', barcode: '809712'),
            $this->leaf('00001001', 'ثابت مجرين W45', '00001', weight: '1.267', barcode: '809712'),
        ], used: ['00009001' => true]);

        $parent = Product::query()->whereNull('parent_id')->sole();
        $this->assertSame(1, Product::query()->where('parent_id', $parent->id)->count());
        $this->assertSame($parent->id, app(LegacyLinks::class)->map('items', Product::class)['00001001']);
    }

    public function test_names_differing_only_in_hamza_and_spaces_group_together(): void
    {
        $this->import([
            $this->leaf('00009010', 'أنبوب  10 مم', '00009', weight: '0.300', barcode: '111'),
            $this->leaf('00001010', 'انبوب 10 مم', '00001', weight: '0.300', barcode: '111'),
        ], allFinishes: true);

        $this->assertSame(1, Product::query()->whereNull('parent_id')->count());
    }

    public function test_same_name_different_weight_makes_two_parents_and_an_exception(): void
    {
        $report = $this->import([
            $this->leaf('00009003', 'يو رفيع', '00009', weight: '0.151', barcode: '5011'),
            $this->leaf('00009004', 'يو رفيع', '00009', weight: '0.160', barcode: '5011'),
        ], allFinishes: true);

        $this->assertSame(2, Product::query()->whereNull('parent_id')->count());
        $this->assertSame(1, $report['exceptions']);
        $exceptions = $this->csv('exceptions.csv');
        $this->assertCount(3, $exceptions, 'header + one row per conflicting parent');
        $this->assertSame('يو رفيع', $exceptions[1][1]);
    }

    public function test_junk_and_inactive_leaves_are_skipped_with_reason(): void
    {
        $report = $this->import([
            $this->leaf('00035001', 'صنف ملغى', '00035'),
            $this->leaf('00009099', 'صنف قديم', '00009', isActive: 'N'),
        ], allFinishes: true);

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(2, $report['skipped']);
        $skipped = $this->csv('items-skipped.csv');
        $this->assertCount(3, $skipped);
        $this->assertSame(['legacy_code', 'name', 'reason'], $skipped[0]);
    }

    public function test_subcategory_leaf_lands_in_two_level_category_priced_per_unit(): void
    {
        $this->import([$this->leaf('000200349001', 'ماتور ميلانو', '000200349', type: 'a_storePicture')], allFinishes: true);

        $product = Product::query()->with('categories.translations')->sole();
        $category = $product->categories->sole();

        $this->assertSame('قطع الوبين', $category->translate('ar')->name);
        $this->assertSame('قطع', Category::query()->find($category->parent_id)->translate('ar')->name);
        $this->assertSame('simple', $product->pricing_type);
        $this->assertNull($product->finish_id);
        $this->assertSame(0, Product::query()->whereNotNull('parent_id')->count());
    }

    public function test_weight_item_without_colour_uses_system_finish(): void
    {
        $this->import([$this->leaf('00018001', 'كوشوك تيرمو 5 مم', '00018', weight: '0.050')], allFinishes: true);

        $product = Product::query()->sole();
        $system = Finish::system();

        $this->assertSame($system->id, (int) $product->finish_id);
        $this->assertSame('weight', $product->pricing_type);
        $this->assertTrue(CategoryFinish::query()->where('finish_id', $system->id)->where('legacy_code', '00018')->exists());
    }

    public function test_running_twice_creates_nothing_new(): void
    {
        $rows = [
            $this->leaf('00009001', 'ثابت مجرين W45', '00009', weight: '1.267', barcode: '809712'),
            $this->leaf('00001001', 'ثابت مجرين W45', '00001', weight: '1.267', barcode: '809712'),
            $this->leaf('000200349001', 'ماتور ميلانو', '000200349', type: 'a_storePicture'),
            $this->leaf('00018001', 'كوشوك تيرمو 5 مم', '00018', weight: '0.050'),
        ];

        $this->import($rows, allFinishes: true);
        $before = $this->counts();
        $this->import($rows, allFinishes: true);

        $this->assertSame($before, $this->counts());
    }

    public function test_category_codes_start_after_legacy_roots(): void
    {
        $this->import([
            $this->leaf('00009001', 'ثابت مجرين W45', '00009', weight: '1.267', barcode: '809712'),
            $this->leaf('00001001', 'ثابت مجرين W45', '00001', weight: '1.267', barcode: '809712'),
        ], allFinishes: true, extraMap: ['00447' => $this->entry('00447', 'تصنيف اخير', 'تصنيف اخير', null, 'simple')]);

        $rootCodes = Category::query()->whereNull('parent_id')->pluck('code')->sort()->values()->all();
        $this->assertSame('00448', $rootCodes[0]);

        $parentSkus = Product::query()->whereNull('parent_id')->pluck('sku')->all();
        $childSkus = Product::query()->whereNotNull('parent_id')->pluck('sku')->all();
        $this->assertSame([], array_intersect($parentSkus, $childSkus));
    }

    /**
     * @param  array<int, array<string, mixed>>  $leaves
     * @param  array<string, true>  $used
     * @param  array<string, CategoryMapEntry>  $extraMap
     * @return array<string, int|bool|string>
     */
    private function import(array $leaves, array $used = [], bool $allFinishes = false, array $extraMap = []): array
    {
        $nodes = [
            $this->row('00009', 'المنيوم الوبين بدون دهان', '0', 'c_cont'),
            $this->row('00001', 'المنيوم الوبين ابيض', '0', 'c_cont'),
            $this->row('00035', 'حذف', '0', 'c_cont'),
            $this->row('00020', 'قطع', '0', 'c_cont'),
            $this->row('000200349', 'قطع الوبين', '00020', 'c_cont'),
            $this->row('00018', 'كوشوك تيرمو بالوزن', '0', 'c_cont'),
        ];

        $map = [
            '00009' => $this->entry('00009', 'المنيوم الوبين بدون دهان', 'ألمنيوم/الوبين', 'بدون دهان', 'weight'),
            '00001' => $this->entry('00001', 'المنيوم الوبين ابيض', 'ألمنيوم/الوبين', 'ابيض', 'weight'),
            '00035' => $this->entry('00035', 'حذف', 'حذف', null, 'simple', CategoryMapEntry::SKIP, 'junk: حذف'),
            '00020' => $this->entry('00020', 'قطع', 'قطع', null, 'simple'),
            '000200349' => $this->entry('000200349', 'قطع الوبين', 'قطع/قطع الوبين', null, 'simple'),
            '00018' => $this->entry('00018', 'كوشوك تيرمو بالوزن', 'كوشوك تيرمو بالوزن', null, 'weight'),
        ] + $extraMap;

        return app(CatalogImporter::class)
            ->withReportDirectory($this->reports)
            ->import(array_merge($nodes, $leaves), $map, $used, $allFinishes, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function leaf(string $id, string $name, string $parent, string $weight = '', string $barcode = '', string $type = 'b_al', string $isActive = 'Y'): array
    {
        return $this->row($id, $name, $parent, $type, ['Prop1' => $weight, 'Prop2' => $weight === '' ? '' : '6.00', 'Barcode' => $barcode, 'isActive' => $isActive]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function row(string $id, string $name, string $parent, string $type, array $extra = []): array
    {
        $row = array_fill_keys(LegacyItemsImporter::LEGACY_COLUMNS, '');

        return array_merge($row, ['ItemId' => $id, 'ItemString' => $name, 'ItemParent' => $parent, 'ItemType' => $type, 'isActive' => 'Y'], $extra);
    }

    private function entry(string $code, string $name, string $path, ?string $finish, string $pricing, string $action = CategoryMapEntry::KEEP, string $note = ''): CategoryMapEntry
    {
        return new CategoryMapEntry($code, $name, 1, $action, $path, $finish, $pricing, $note);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function csv(string $file): array
    {
        $lines = file($this->reports.DIRECTORY_SEPARATOR.$file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0] ?? '');

        return array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $prefix = config('items.table_prefix');

        return [
            'categories' => DB::table($prefix.'categories')->count(),
            'category_translations' => DB::table($prefix.'category_translations')->count(),
            'finishes' => DB::table($prefix.'finishes')->count(),
            'category_finishes' => DB::table($prefix.'category_finishes')->count(),
            'products' => DB::table($prefix.'products')->count(),
            'product_translations' => DB::table($prefix.'product_translations')->count(),
            'products_categories' => DB::table($prefix.'products_categories')->count(),
            'legacy_links' => DB::table('legacy_links')->count(),
        ];
    }
}
