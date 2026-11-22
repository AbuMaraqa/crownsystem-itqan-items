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
        $this->assertSame(['kind', 'category_path', 'name', 'legacy_codes', 'weights', 'barcodes'], $exceptions[0]);
        $this->assertSame('same_name', $exceptions[1][0]);
        $this->assertSame('يو رفيع', $exceptions[1][2]);
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

    public function test_finish_names_differing_only_in_letter_case_are_one_finish(): void
    {
        $this->import([
            $this->leaf('00008001', 'ثابت مجرين W45', '00008', weight: '1.267', barcode: '809712'),
            $this->leaf('00168001', 'ثابت مجرين W45', '00168', weight: '1.267', barcode: '809712'),
        ], allFinishes: true, extraMap: [
            '00008' => $this->entry('00008', 'المنيوم الوبين لون FINE IRON', 'ألمنيوم/الوبين', 'FINE IRON', 'weight'),
            '00168' => $this->entry('00168', 'المنيوم كليل سيستم لون fine Iron', 'ألمنيوم/كليل سيستم', 'fine Iron', 'weight'),
        ], extraNodes: [
            $this->row('00008', 'المنيوم الوبين لون FINE IRON', '0', 'a_al'),
            $this->row('00168', 'المنيوم كليل سيستم لون fine Iron', '0', 'a_al'),
        ]);

        $this->assertSame(1, Finish::query()->whereRaw('LOWER(name) = ?', ['fine iron'])->count());
        $this->assertSame(1, Product::query()->whereNotNull('parent_id')->where('sku', '00008001')->count());
        $this->assertSame(1, Product::query()->whereNotNull('parent_id')->where('sku', '00168001')->count());
    }

    public function test_profile_in_two_price_groups_of_different_systems_is_one_parent_with_two_children(): void
    {
        $this->import([
            $this->leaf('00024001', 'ثابت مجرين', '00024', weight: '1.100', barcode: '555'),
            $this->leaf('00033001', 'ثابت مجرين', '00033', weight: '1.100', barcode: '555'),
        ], allFinishes: true, extraMap: [
            '00024' => $this->entry('00024', 'المنيوم سبير رال 7016', 'ألمنيوم/سبير', 'رال 7016', 'weight'),
            '00033' => $this->entry('00033', 'المنيوم رويال سيستم بدون دهان', 'ألمنيوم/رويال سيستم', 'بدون دهان', 'weight'),
        ], extraNodes: [
            $this->row('00024', 'المنيوم سبير رال 7016', '0', 'a_al'),
            $this->row('00033', 'المنيوم رويال سيستم بدون دهان', '0', 'a_al'),
        ]);

        $parent = Product::query()->whereNull('parent_id')->sole();
        $groups = Product::query()->where('parent_id', $parent->id)->orderBy('sku')->pluck('category_finish_id', 'sku');

        $this->assertSame(['00024001', '00033001'], $groups->keys()->all());
        $this->assertSame('00024', CategoryFinish::query()->find($groups['00024001'])->legacy_code);
        $this->assertSame('00033', CategoryFinish::query()->find($groups['00033001'])->legacy_code);
    }

    public function test_children_carry_their_price_group_and_no_barcode(): void
    {
        $this->import([$this->leaf('00009001', 'ثابت مجرين W45', '00009', weight: '1.267', barcode: '809712')], allFinishes: true);

        $child = Product::query()->whereNotNull('parent_id')->sole();

        $this->assertNull($child->barcode);
        $this->assertSame('00009', CategoryFinish::query()->find($child->category_finish_id)->legacy_code);
        $this->assertSame('809712', Product::query()->find($child->parent_id)->barcode);
    }

    public function test_finish_spelling_variants_in_one_path_make_one_child(): void
    {
        $this->import([
            $this->leaf('00008001', 'ثابت مجرين W45', '00008', weight: '1.267', barcode: '809712'),
            $this->leaf('00011001', 'ثابت مجرين W45', '00011', weight: '1.267', barcode: '809712'),
        ], allFinishes: true, extraMap: [
            '00008' => $this->entry('00008', 'المنيوم الوبين لون FINE IRON', 'ألمنيوم/الوبين', 'FINE IRON', 'weight'),
            '00011' => $this->entry('00011', 'المنيوم الوبين fine Iron', 'ألمنيوم/الوبين', 'fine Iron', 'weight'),
        ], extraNodes: [
            $this->row('00008', 'المنيوم الوبين لون FINE IRON', '0', 'a_al'),
            $this->row('00011', 'المنيوم الوبين fine Iron', '0', 'a_al'),
        ]);

        $this->assertSame(1, Product::query()->whereNotNull('parent_id')->count());
        $links = app(LegacyLinks::class)->map('items', Product::class);
        $this->assertSame($links['00008001'], $links['00011001']);
    }

    public function test_same_barcode_and_weight_with_different_names_is_reported(): void
    {
        $report = $this->import([
            $this->leaf('00009021', 'عليون مجرين مفتوح', '00009', weight: '0.500', barcode: '777'),
            $this->leaf('00009022', 'عليون مجريين مفتوح', '00009', weight: '0.500', barcode: '777'),
        ], allFinishes: true);

        $kinds = array_column(array_slice($this->csv('exceptions.csv'), 1), 0);
        $this->assertSame(['same_barcode', 'same_barcode'], $kinds);
        $this->assertSame(1, $report['exceptions']);
    }

    public function test_colour_child_is_mirrored_to_the_colour_attribute(): void
    {
        $this->import([$this->leaf('00009001', 'ثابت مجرين W45', '00009', weight: '1.267', barcode: '809712')], allFinishes: true);

        $child = Product::query()->whereNotNull('parent_id')->with('finish')->sole();
        $valueId = $child->finish->attribute_value_id;

        $this->assertNotNull($valueId);
        $this->assertSame(Finish::colourAttributeId(), (int) DB::table(config('items.table_prefix').'attribute_values')->where('id', $valueId)->value('attribute_id'));
        $this->assertTrue(DB::table(config('items.table_prefix').'product_attribute_value')
            ->where('product_id', $child->id)->where('attribute_id', $valueId)->exists());
    }

    public function test_rerun_after_skipping_a_node_deactivates_its_children(): void
    {
        $rows = [
            $this->leaf('00009001', 'ثابت مجرين W45', '00009', weight: '1.267', barcode: '809712'),
            $this->leaf('00001001', 'ثابت مجرين W45', '00001', weight: '1.267', barcode: '809712'),
        ];
        $this->import($rows, allFinishes: true);

        $report = $this->import($rows, allFinishes: true, extraMap: [
            '00001' => $this->entry('00001', 'المنيوم الوبين ابيض', 'ألمنيوم/الوبين', 'ابيض', 'weight', CategoryMapEntry::SKIP, 'owner'),
        ]);

        $this->assertSame(1, $report['children_deactivated']);
        $this->assertSame(0, (int) Product::query()->where('sku', '00001001')->value('is_active'));
        $this->assertSame(1, (int) Product::query()->where('sku', '00009001')->value('is_active'));
    }

    public function test_parent_category_is_the_most_frequent_system(): void
    {
        $this->import([
            $this->leaf('00099001', 'زاوية', '00099', weight: '0.400', barcode: '42'),
            $this->leaf('00100001', 'زاوية', '00100', weight: '0.400', barcode: '42'),
            $this->leaf('00009001', 'زاوية', '00009', weight: '0.400', barcode: '42'),
            $this->leaf('00303001', 'زاوية', '00303', weight: '0.400', barcode: '42'),
        ], allFinishes: true, extraMap: [
            '00099' => $this->entry('00099', 'المنيوم نابكو ابيض', 'ألمنيوم/نابكو', 'ابيض', 'weight'),
            '00100' => $this->entry('00100', 'المنيوم نابكو كريم', 'ألمنيوم/نابكو', 'كريم', 'weight'),
            '00303' => $this->entry('00303', 'المنيوم دهان جلوسي اكسبرس لون ابيض', 'ألمنيوم', 'جلوسي اكسبرس لون ابيض', 'weight', note: 'painter'),
        ], extraNodes: [
            $this->row('00099', 'المنيوم نابكو ابيض', '0', 'a_al'),
            $this->row('00100', 'المنيوم نابكو كريم', '0', 'a_al'),
            $this->row('00303', 'المنيوم دهان جلوسي اكسبرس لون ابيض', '0', 'a_al'),
        ]);

        $parent = Product::query()->whereNull('parent_id')->with('categories.translations')->sole();

        $this->assertSame('نابكو', $parent->categories->sole()->translate('ar')->name);
        $this->assertSame(4, Product::query()->where('parent_id', $parent->id)->count());
    }

    public function test_parent_gets_unit_and_english_name(): void
    {
        $this->import([
            $this->row('00009001', 'ثابت مجرين W45', '00009', 'b_al', [
                'Prop1' => '1.267', 'Prop2' => '6.00', 'Prop3' => 'A-12', 'Prop6' => 'Fixed frame W45', 'Barcode' => '809712', 'itemunit' => 'كغم',
            ]),
        ], allFinishes: true);

        $parent = Product::query()->whereNull('parent_id')->sole();

        $this->assertNotNull($parent->unit_id);
        $this->assertSame('A-12', $parent->shelf);
        $this->assertSame('Fixed frame W45', DB::table(config('items.table_prefix').'product_translations')
            ->where('product_id', $parent->id)->where('locale', 'en')->value('name'));
    }

    public function test_seller_gets_cost_limits_and_secondary_barcodes(): void
    {
        $this->import([
            $this->row('000200349001', 'ماتور ميلانو', '000200349', 'a_storePicture', [
                'ItemCost' => 21.63, 'ItemMax' => 40, 'ItemMin1' => 3, 'ItemMin2' => 0, 'Barcode' => '111█222',
            ]),
            $this->row('00009001', 'ثابت مجرين W45', '00009', 'b_al', [
                'Prop1' => '1.267', 'Prop2' => '6.00', 'Barcode' => '809712', 'ItemCost' => 18.5,
            ]),
        ], allFinishes: true);

        $accessory = Product::query()->whereNull('parent_id')->where('barcode', '111')->sole();
        $this->assertSame('21.63', number_format((float) $accessory->cost_price, 2, '.', ''));
        $this->assertSame(40, (int) $accessory->max_limit);
        $this->assertSame(3, (int) $accessory->min_limit_1);
        $this->assertNull($accessory->min_limit_2);
        $this->assertSame(['222'], DB::table(config('items.table_prefix').'product_barcodes')->where('product_id', $accessory->id)->pluck('barcode')->all());

        $child = Product::query()->where('sku', '00009001')->sole();
        $this->assertSame('18.50', number_format((float) $child->cost_price, 2, '.', ''));
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
    private function import(array $leaves, array $used = [], bool $allFinishes = false, array $extraMap = [], array $extraNodes = []): array
    {
        $nodes = [...$extraNodes,
            $this->row('00009', 'المنيوم الوبين بدون دهان', '0', 'c_cont'),
            $this->row('00001', 'المنيوم الوبين ابيض', '0', 'c_cont'),
            $this->row('00035', 'حذف', '0', 'c_cont'),
            $this->row('00020', 'قطع', '0', 'c_cont'),
            $this->row('000200349', 'قطع الوبين', '00020', 'c_cont'),
            $this->row('00018', 'كوشوك تيرمو بالوزن', '0', 'c_cont'),
        ];

        // extraMap اولاً: يتقدم على الخريطة الأساسية لمفتاح موجود (اختبار تعديل ملف الربط).
        $map = $extraMap + [
            '00009' => $this->entry('00009', 'المنيوم الوبين بدون دهان', 'ألمنيوم/الوبين', 'بدون دهان', 'weight'),
            '00001' => $this->entry('00001', 'المنيوم الوبين ابيض', 'ألمنيوم/الوبين', 'ابيض', 'weight'),
            '00035' => $this->entry('00035', 'حذف', 'حذف', null, 'simple', CategoryMapEntry::SKIP, 'junk: حذف'),
            '00020' => $this->entry('00020', 'قطع', 'قطع', null, 'simple'),
            '000200349' => $this->entry('000200349', 'قطع الوبين', 'قطع/قطع الوبين', null, 'simple'),
            '00018' => $this->entry('00018', 'كوشوك تيرمو بالوزن', 'كوشوك تيرمو بالوزن', null, 'weight'),
        ];

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
