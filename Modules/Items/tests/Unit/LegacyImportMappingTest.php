<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;
use Modules\Items\Services\LegacyItemsImporter;
use Modules\Items\Support\LegacyItemFields;
use Modules\Items\Support\Search\SearchCapabilities;
use Tests\TestCase;

/**
 * استيراد جدول items القديم: معنى حقول Prop يتبع نوع الصنف، والباركودات تُفصل.
 * المستورد يدير معاملته بنفسه عبر PDO، فهذا الاختبار بلا معاملة ويحذف بياناته بنفسه.
 */
class LegacyImportMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, string|null>
     */
    protected $connectionsToTransact = [];

    private ?string $dump = null;

    /**
     * @var array<int, string>
     */
    private array $skus = [];

    protected function setUp(): void
    {
        parent::setUp();

        SearchCapabilities::forget();
    }

    protected function tearDown(): void
    {
        DB::table('items_products')->whereIn('sku', $this->skus)->delete();
        DB::table('items_categories')->whereIn('code', $this->skus)->delete();
        DB::table('items_units')->where('slug', 'like', 'legacy-unit-%')->delete();

        if ($this->dump && is_file($this->dump)) {
            unlink($this->dump);
        }

        SearchCapabilities::forget();

        parent::tearDown();
    }

    public function test_a_profile_row_maps_prop1_to_weight_per_meter_not_weight(): void
    {
        $this->import([$this->row('ZB1', 'b_al', ['Prop1' => '0.444', 'Prop2' => '6', 'Prop3' => 'A7', 'Prop5' => '25'])]);

        $product = $this->productBySku('ZB1');

        $this->assertSame('0.4440', $product->weight_per_meter);
        $this->assertSame('6.0000', $product->length);
        $this->assertSame('A7', $product->shelf);
        $this->assertNull($product->weight);
        // معنى Prop5 في b_al لم يُحسم بعد: يبقى في meta_data فقط.
        $this->assertNull($product->bundle_count);
        $this->assertSame('25', $product->meta_data['props']['Prop5']);
    }

    public function test_a_picture_row_maps_like_a_profile_and_keeps_its_pricing_type(): void
    {
        $this->import([$this->row('ZP1', 'a_storePicture', ['Prop1' => '1.25', 'Prop2' => '6', 'Prop3' => 'R-3'])]);

        $product = $this->productBySku('ZP1');

        $this->assertSame('1.2500', $product->weight_per_meter);
        $this->assertSame('6.0000', $product->length);
        $this->assertSame('R-3', $product->shelf);
        $this->assertNull($product->weight);
        $this->assertSame('simple', $product->pricing_type);
    }

    public function test_non_numeric_picture_measures_stay_null_and_remain_in_meta_data(): void
    {
        $this->import([
            $this->row('ZP2', 'a_storePicture', ['Prop1' => 'R-3', 'Prop2' => '6']),
            $this->row('ZP3', 'a_storePicture', ['Prop1' => '1.5', 'Prop2' => 'long']),
        ]);

        $first = $this->productBySku('ZP2');
        $this->assertNull($first->weight_per_meter);
        $this->assertSame('6.0000', $first->length);
        $this->assertNull($first->shelf);
        $this->assertSame('R-3', $first->meta_data['props']['Prop1']);

        $second = $this->productBySku('ZP3');
        $this->assertSame('1.5000', $second->weight_per_meter);
        $this->assertNull($second->length);
        $this->assertSame('long', $second->meta_data['props']['Prop2']);
    }

    public function test_zero_means_unset_and_values_beyond_the_column_do_not_stop_the_import(): void
    {
        $this->import([
            $this->row('ZG1', 'b_al', ['Prop1' => '0.00', 'Prop2' => '0', 'Prop3' => '0']),
            $this->row('ZG2', 'a_al', ['Prop1' => '0']),
            $this->row('ZG3', 'b_al', ['Prop1' => '99999999', 'Prop2' => '-3']),
            $this->row('ZG4', 'b_al', []),
        ]);

        foreach (['ZG1', 'ZG2', 'ZG3', 'ZG4'] as $sku) {
            $product = $this->productBySku($sku);

            $this->assertNull($product->weight_per_meter, $sku);
            $this->assertNull($product->length, $sku);
            $this->assertNull($product->shelf, $sku);
        }

        $this->assertSame('99999999', $this->productBySku('ZG3')->meta_data['props']['Prop1']);
    }

    public function test_real_gaps_are_tolerated_and_inactive_rows_are_imported(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;

        $stats = $this->import([
            $this->row('ZH1', 'b_al', ['Prop1' => '1.2'], null),
            $this->row('ZH2', 'b_al', [], "{$sep}ZH2-B{$sep}ZH2-C"),
            $this->row('ZH3', 'b_al', [], '', ['active' => 'N']),
        ]);

        $this->assertSame(0, $stats['products_not_imported']);
        $this->assertNull($this->productBySku('ZH1')->barcode);

        // الجزء الاول فارغ: يُرفع اول جزء غير فارغ، وتبقى المواضع.
        $this->assertSame('ZH2-B', $this->productBySku('ZH2')->barcode);
        $this->assertSame(
            ['ZH2-C' => 2],
            ProductBarcode::query()->where('product_id', $this->productBySku('ZH2')->id)->pluck('sort_order', 'barcode')->all()
        );

        $inactive = $this->productBySku('ZH3');
        $this->assertSame(0, (int) $inactive->is_active);
        $this->assertNull($inactive->barcode);
    }

    public function test_weight_items_are_weight_priced_from_the_first_import_and_edits_stay(): void
    {
        $rows = [$this->row('ZT1', 'b_al', []), $this->row('ZT2', 'a_store', [])];

        $this->import($rows);

        $this->assertSame('weight', $this->productBySku('ZT1')->pricing_type);
        $this->assertSame('simple', $this->productBySku('ZT2')->pricing_type);

        DB::table('items_products')->where('sku', 'ZT1')->update(['pricing_type' => 'simple']);

        $this->import($rows);

        $this->assertSame('simple', $this->productBySku('ZT1')->pricing_type);
    }

    public function test_units_are_mapped_and_created_on_first_use(): void
    {
        $existing = DB::table('items_units')->insertGetId(['slug' => 'zz-existing-unit']);
        DB::table('items_unit_translations')->insert(['unit_id' => $existing, 'locale' => 'ar', 'name' => 'حبة']);

        try {
            $stats = $this->import([
                $this->row('ZU1', 'b_al', [], null, ['unit' => 'كغم']),
                $this->row('ZU2', 'b_al', [], null, ['unit' => ' كغم ']),
                $this->row('ZU3', 'a_store', [], null, ['unit' => 'عامود']),
                $this->row('ZU4', 'a_store', [], null, ['unit' => 'حبة']),
                $this->row('ZU5', 'a_store', [], null, ['unit' => '']),
            ]);

            $this->assertSame(2, $stats['units_created']);

            $kilo = $this->productBySku('ZU1');
            $this->assertNotNull($kilo->unit_id);
            $this->assertSame('كغم', $kilo->unit->translate('ar')->name);
            $this->assertSame($kilo->unit_id, $this->productBySku('ZU2')->unit_id);
            $this->assertSame('كغم', $kilo->meta_data['unit']);
            $this->assertSame('عامود', $this->productBySku('ZU3')->unit->translate('ar')->name);
            $this->assertSame($existing, $this->productBySku('ZU4')->unit_id);
            $this->assertNull($this->productBySku('ZU5')->unit_id);

            // اعادة الاستيراد لا تُنشئ وحدات ولا تغيّر وحدة عُدّلت.
            DB::table('items_products')->where('sku', 'ZU3')->update(['unit_id' => $existing]);

            $again = $this->import([
                $this->row('ZU1', 'b_al', [], null, ['unit' => 'كغم']),
                $this->row('ZU3', 'a_store', [], null, ['unit' => 'عامود']),
            ]);

            $this->assertSame(0, $again['units_created']);
            $this->assertSame($existing, $this->productBySku('ZU3')->unit_id);
        } finally {
            DB::table('items_products')->whereIn('sku', $this->skus)->delete();
            DB::table('items_units')->where('id', $existing)->delete();
        }
    }

    public function test_prop6_becomes_the_english_name_only_when_it_is_not_a_number(): void
    {
        $stats = $this->import([
            $this->row('ZN1', 'a_store', ['Prop6' => 'Door hinge']),
            $this->row('ZN2', 'b_al', ['Prop6' => '1.267']),
            $this->row('ZN3', 'b_al', ['Prop6' => '']),
        ]);

        $this->assertSame(1, $stats['english_names_created']);
        $this->assertSame('Door hinge', $this->productBySku('ZN1')->translate('en')->name);
        $this->assertSame('صنف ZN1', $this->productBySku('ZN1')->translate('ar')->name);
        $this->assertNull($this->productBySku('ZN2')->translate('en'));
        $this->assertSame('1.267', $this->productBySku('ZN2')->meta_data['props']['Prop6']);
        $this->assertNull($this->productBySku('ZN3')->translate('en'));

        // اعادة الاستيراد لا تمحو ترجمة عُدّلت ولا تكررها.
        DB::table('items_product_translations')->where('product_id', $this->productBySku('ZN1')->id)->where('locale', 'en')->update(['name' => 'Edited']);

        $this->import([$this->row('ZN1', 'a_store', ['Prop6' => 'Door hinge'])]);

        $this->assertSame(1, DB::table('items_product_translations')->where('product_id', $this->productBySku('ZN1')->id)->where('locale', 'en')->count());
        $this->assertSame('Edited', $this->productBySku('ZN1')->translate('en')->name);
    }

    public function test_sub_items_may_repeat_a_name_inside_the_same_main_item(): void
    {
        $stats = $this->import([
            $this->row('ZM0', 'a_al', [], null, ['name' => 'نوع ألمنيوم']),
            $this->row('ZM0001', 'b_al', [], null, ['name' => 'ثابت مكرر', 'parent' => 'ZM0']),
            $this->row('ZM0002', 'b_al', [], null, ['name' => 'ثابت مكرر', 'parent' => 'ZM0']),
        ]);

        $this->assertSame(0, $stats['products_not_imported']);
        $this->assertSame('ثابت مكرر', $this->productBySku('ZM0001')->translate('ar')->name);
        $this->assertSame('ثابت مكرر', $this->productBySku('ZM0002')->translate('ar')->name);
        $this->assertNotSame($this->productBySku('ZM0001')->id, $this->productBySku('ZM0002')->id);
    }

    public function test_the_parent_comes_from_item_parent_and_never_from_the_code_length(): void
    {
        // رقم الابن لا يبدأ برقم ابيه، وطوله لا يطابق ChRange؛ وصنف يبدأ رقمه برقم فئة ليس ابنها.
        $this->import([
            $this->row('ZQ1', 'c_cont', [], null, ['name' => 'الفئة الاولى']),
            $this->row('ZQ2', 'c_cont', [], null, ['name' => 'الفئة الثانية']),
            $this->row('ZQ2005', 'a_store', [], null, ['parent' => 'ZQ1']),
            $this->row('ZQ1009', 'a_store', [], null, ['parent' => 'ZQ2']),
            $this->row('ZQ1XX', 'a_store', [], null, ['parent' => '0']),
        ]);

        $inCategory = fn (string $sku) => $this->productBySku($sku)->categories()->pluck('code')->all();

        $this->assertSame(['ZQ1'], $inCategory('ZQ2005'));
        $this->assertSame(['ZQ2'], $inCategory('ZQ1009'));
        $this->assertSame([], $inCategory('ZQ1XX'));
        $this->assertSame('ZQ1', $this->productBySku('ZQ2005')->meta_data['legacy_parent_id']);
    }

    public function test_real_rows_from_the_owner_export_import_exactly(): void
    {
        // صفوف حقيقية كما هي من اول ملف التصدير: الارقام بلا علامات تنصيص، والحقول الفارغة ''.
        $this->skus = array_merge($this->skus, ['00001', '00001001', '00001002', '00001003', '00001011', '00001022', '000200068', '0001917002', '00002', '00002009', '000300275', '00123027', '00012', '00422']);

        $stats = $this->importTuples([
            "('0','شجرة الأصناف','0',NULL,0,'قطعة','',0,0,0,0,0,'',4,NULL,NULL,NULL,NULL,NULL,NULL,'','','','Y')",
            "('00001','المنيوم الوبين ابيض','White','a_al',22.58,'كغم','0',1,0,0,0,0,'اميرة 30.9.2020',3,'0',NULL,NULL,NULL,'1','','','','','Y')",
            "('00001001','ثابت مجرين W45','809712','b_al',0,'كغم','00001',1,0,0,0,0,'الصورة غير موجودة ',0,'1.267','6','ZZZ','5.00','6','1.267','','','','Y')",
            "('00001002','يو 3سم مع فرشاية K5950','181004','b_al',0,'كغم','00001',1,26,18,10,0,'',0,'0.363','6.00','R13',NULL,'1','','','','','Y')",
            "('00001003','يو رفيع  K5055','5011','b_al',0,'كغم','00001',1,40,30,10,0,'',0,'0.151','6.00','R13',NULL,'1','','','','','Y')",
            "('000200068','ماتور ميلانو 40 كغم','65','a_storePicture',0,'قطعة','00020',1,0,0,0,0,'',0,'0',NULL,NULL,NULL,'1','','','','','Y')",
            "('00001011','سادة دبل K3336','142004','b_al',0,'كغم','00001',1,120,100,60,0,'',0,'0.7','6.00','ZZZZ','0','1█1█1','','0','','','Y')",
            "('00001022','صندوق مسح 423032','BLT398█BOXBLT398','b_al',0,'كغم','00001',1,100,60,30,0,'',0,'0.756','6.00','مشتاح كبير ع1','0','1█1','','','','','Y')",
            "('0001917002','عمود اباجور اور بدون دهان 6 متر  ','BLT237- MF - 6M█BLT237 -MF █6500█9506667█INTOP█M.F█6█BLT237AB','a_storePicture',12.53,'عامود','0001917',1,16000,11000,6000,0,'',0,'0.15','6','','17.4','1█1█1█1█1█1█1█1','','0','','','Y')",
            "('00002','المنيوم الوبين ميش','Meshi 21.5','a_al',0,'قطعة','0',1,0,0,0,0,'',3,'0',NULL,NULL,NULL,NULL,NULL,'','','','Y')",
            "('00002009','عليون مجرين ونصف خفيف','819002','b_al',0,'كغم','00002',1,12,8,4,0,'',0,'1.200','6.00','AK11',NULL,'1','','','','','N')",
            "('000300275','Angle 2/2.5 EF 1.2 mm','█Sapir█INTOP','b_al',0,'كغم','00030',1,0,0,0,0,'',0,'0.1420','6.00','','0','1','','','','','Y')",
            "('00123027','منخل رفيع مع فرشايه K0932','BLT038A█K 0932█12010','b_al',0,'كغم','00123',1,180,110,50,0,'',0,'0.3220','6','AP 06','0.37','0.37█1█1','0.37','','','','Y')",
            "('00012',' مجموعة فرشاية ','00012','c_cont',0,'كغم','0',1,0,0,0,0,'',3,NULL,NULL,NULL,NULL,'1','','','','','Y')",
            "('00422','خدمات -تم عملها ل الوم ','','a_storeNoteC1',0,'كغم','0',1,0,0,0,0,'',0,'','','','','1','','','','','Y')",
        ]);

        // الجذر لا يُستورد. a_al له ابناء صنف حقيقي وله توأم فئة، و c_cont فئة ولو بلا ابناء.
        $this->assertSame(3, $stats['old_category_nodes']);
        $this->assertSame(0, $stats['products_not_imported']);
        $this->assertSame(1, $stats['first_barcode_pack_quantity_ignored']);
        $this->assertNull(Product::query()->where('sku', '0')->first());
        $this->assertSame('22.58', $this->productBySku('00001')->cost_price);
        $this->assertSame('Meshi 21.5', $this->productBySku('00002')->barcode);

        $profile = $this->productBySku('00001001');
        $this->assertSame('ثابت مجرين W45', $profile->translate('ar')->name);
        $this->assertSame('809712', $profile->barcode);
        $this->assertSame('1.2670', $profile->weight_per_meter);
        $this->assertSame('6.0000', $profile->length);
        $this->assertSame('ZZZ', $profile->shelf);
        $this->assertNull($profile->weight);
        // Prop6 هنا رقم، و Prop5 لم يُحسم.
        $this->assertNull($profile->translate('en'));
        $this->assertNull($profile->bundle_count);
        $this->assertSame('6', $profile->meta_data['props']['Prop5']);
        $this->assertSame('weight', $profile->pricing_type);
        $this->assertSame('كغم', $profile->unit->translate('ar')->name);
        $this->assertSame('00001', $profile->meta_data['legacy_parent_id']);
        $this->assertSame(['00001'], $profile->categories()->pluck('code')->all());

        $second = $this->productBySku('00001002');
        $this->assertSame('0.3630', $second->weight_per_meter);
        $this->assertSame('6.0000', $second->length);
        $this->assertSame('R13', $second->shelf);
        $this->assertSame(26, (int) $second->max_limit);
        $this->assertSame($profile->unit_id, $second->unit_id);

        // Prop1 = '0' يعني غير محدد، وابوه ليس في العينة.
        $picture = $this->productBySku('000200068');
        $this->assertSame('65', $picture->barcode);
        $this->assertNull($picture->weight_per_meter);
        $this->assertNull($picture->length);
        $this->assertNull($picture->shelf);
        $this->assertSame('simple', $picture->pricing_type);
        $this->assertSame('قطعة', $picture->unit->translate('ar')->name);

        $this->assertSame(0, ProductBarcode::query()->whereIn('product_id', [$profile->id, $second->id, $picture->id])->count());

        $barcodes = fn (Product $product): array => ProductBarcode::query()
            ->where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ProductBarcode $row): array => [$row->barcode, $row->sort_order, $row->pack_quantity])
            ->all();

        // قائمة Prop5 اطول من الباركودات: لا تُقرأ كميات.
        $singleBarcode = $this->productBySku('00001011');
        $this->assertSame('142004', $singleBarcode->barcode);
        $this->assertSame('0.7000', $singleBarcode->weight_per_meter);
        $this->assertSame([], $barcodes($singleBarcode));
        $this->assertSame('1█1█1', $singleBarcode->meta_data['props']['Prop5']);

        // قائمة موازية للباركودات.
        $parallel = $this->productBySku('00001022');
        $this->assertSame('BLT398', $parallel->barcode);
        $this->assertSame([['BOXBLT398', 1, '1.000000']], $barcodes($parallel));
        $this->assertSame('مشتاح كبير ع1', $parallel->shelf);

        // صورة بوزن متر وطول، وباركوداتها كلها بمواضعها.
        $measured = $this->productBySku('0001917002');
        $this->assertSame('0.1500', $measured->weight_per_meter);
        $this->assertSame('6.0000', $measured->length);
        $this->assertNull($measured->shelf);
        $this->assertSame('simple', $measured->pricing_type);
        $this->assertSame('عامود', $measured->unit->translate('ar')->name);
        $this->assertSame('BLT237- MF - 6M', $measured->barcode);
        $this->assertSame(
            [['BLT237 -MF', 1], ['6500', 2], ['9506667', 3], ['INTOP', 4], ['M.F', 5], ['6', 6], ['BLT237AB', 7]],
            array_map(fn (array $row): array => [$row[0], $row[1]], $barcodes($measured))
        );

        // غير النشط يُستورد، وابوه من ItemParent.
        $inactive = $this->productBySku('00002009');
        $this->assertSame(0, (int) $inactive->is_active);
        $this->assertSame('1.2000', $inactive->weight_per_meter);
        $this->assertSame(['00002'], $inactive->categories()->pluck('code')->all());

        // الجزء الاول فارغ: يُرفع اول جزء، والموضع يبقى.
        $firstBlank = $this->productBySku('000300275');
        $this->assertSame('Sapir', $firstBlank->barcode);
        $this->assertSame([['INTOP', 2, '1.000000']], $barcodes($firstBlank));
        $this->assertNull($firstBlank->shelf);

        // كمية الباركود الاول لا مكان لها، وتبقى في meta_data. و Prop6 هنا رقم.
        $firstPack = $this->productBySku('00123027');
        $this->assertSame('BLT038A', $firstPack->barcode);
        $this->assertSame([['K 0932', 1, '1.000000'], ['12010', 2, '1.000000']], $barcodes($firstPack));
        $this->assertSame('0.37█1█1', $firstPack->meta_data['props']['Prop5']);
        $this->assertNull($firstPack->translate('en'));

        // فئة بلا ابناء تبقى فئة، ونوع غير مؤكد لا تُملأ له اعمدة.
        $this->assertNull(Product::query()->where('sku', '00012')->first());
        $this->assertSame('old-cat-00012', \Modules\Items\Entities\Category::query()->where('code', '00012')->value('slug'));

        $unknown = $this->productBySku('00422');
        $this->assertNull($unknown->barcode);
        $this->assertNull($unknown->shelf);
        $this->assertSame('a_storeNoteC1', $unknown->meta_data['legacy_item_type']);
    }

    public function test_main_aluminium_and_store_rows_map_prop1_to_the_shelf(): void
    {
        $this->import([
            $this->row('ZA1', 'a_al', ['Prop1' => '12']),
            $this->row('ZS1', 'a_store', ['Prop1' => 'B-4']),
            $this->row('ZS2', 'a_storeNote', ['Prop1' => '9']),
        ]);

        foreach (['ZA1' => '12', 'ZS1' => 'B-4', 'ZS2' => '9'] as $sku => $shelf) {
            $product = $this->productBySku($sku);

            $this->assertSame($shelf, $product->shelf);
            $this->assertNull($product->weight);
            $this->assertNull($product->weight_per_meter);
        }
    }

    public function test_area_and_volume_rows_map_shelf_and_length(): void
    {
        $this->import([
            $this->row('ZV1', 'a_storeArea', ['Prop1' => 'C1', 'Prop2' => '2.5']),
            $this->row('ZV2', 'a_storeVol', ['Prop1' => 'C2', 'Prop2' => '3']),
        ]);

        $this->assertSame('C1', $this->productBySku('ZV1')->shelf);
        $this->assertSame('2.5000', $this->productBySku('ZV1')->length);
        $this->assertSame('C2', $this->productBySku('ZV2')->shelf);
        $this->assertSame('3.0000', $this->productBySku('ZV2')->length);
    }

    public function test_other_types_leave_the_columns_alone_and_keep_props_in_meta_data(): void
    {
        $this->import([$this->row('ZO1', 'a_Shoes', ['Prop1' => 'SIZE|R1', 'Prop2' => 'red'])]);

        $product = $this->productBySku('ZO1');

        $this->assertNull($product->shelf);
        $this->assertNull($product->length);
        $this->assertNull($product->weight);
        $this->assertSame('SIZE|R1', $product->meta_data['props']['Prop1']);
        $this->assertSame('a_Shoes', $product->meta_data['legacy_item_type']);
    }

    public function test_barcodes_split_with_pack_quantities_from_the_same_position(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;

        $stats = $this->import([$this->row('ZC1', 'a_store', ['Prop5' => "1{$sep}12{$sep}24"], "ZC1-A{$sep}ZC1-B{$sep}*ZC1-P")]);

        $product = $this->productBySku('ZC1');
        $rows = ProductBarcode::query()->where('product_id', $product->id)->orderBy('sort_order')->get();

        $this->assertSame('ZC1-A', $product->barcode);
        $this->assertSame(['ZC1-B', 'ZC1-P'], $rows->pluck('barcode')->all());
        $this->assertSame(['12.000000', '24.000000'], $rows->pluck('pack_quantity')->all());
        $this->assertSame([false, true], $rows->pluck('is_prefix')->all());
        $this->assertSame(2, $stats['barcode_rows_created']);
    }

    public function test_barcode_positions_are_kept_in_sort_order(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;

        // الموضع له معنى: طباعة الالمنيوم تختار الباركود رقم N. الفراغ لا يزحزح ما بعده.
        $this->import([
            $this->row('ZF1', 'b_al', [], "ZF1-0{$sep}ZF1-1{$sep}{$sep}ZF1-3"),
            $this->row('ZF2', 'a_store', [], "*ZF2-0{$sep}ZF2-1{$sep}ZF2-2"),
        ]);

        $positions = fn (string $sku): array => ProductBarcode::query()
            ->where('product_id', $this->productBySku($sku)->id)
            ->orderBy('sort_order')
            ->pluck('sort_order', 'barcode')
            ->all();

        $this->assertSame('ZF1-0', $this->productBySku('ZF1')->barcode);
        $this->assertSame(['ZF1-1' => 1, 'ZF1-3' => 3], $positions('ZF1'));

        // باركود البادئة لا يكون رئيسياً، وموضع الرئيسي يبقى خالياً.
        $this->assertSame('ZF2-1', $this->productBySku('ZF2')->barcode);
        $this->assertSame(['ZF2-0' => 0, 'ZF2-2' => 2], $positions('ZF2'));
    }

    public function test_a_profile_prop5_list_of_the_same_length_gives_pack_quantities(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;

        $this->import([$this->row('ZB3', 'b_al', ['Prop5' => "1{$sep}6{$sep}12"], "ZB3-A{$sep}ZB3-B{$sep}ZB3-C")]);

        $product = $this->productBySku('ZB3');

        $this->assertSame('ZB3-A', $product->barcode);
        $this->assertSame('6.000000', ProductBarcode::query()->where('barcode', 'ZB3-B')->value('pack_quantity'));
        $this->assertSame('12.000000', ProductBarcode::query()->where('barcode', 'ZB3-C')->value('pack_quantity'));
        $this->assertNull($product->bundle_count);
    }

    public function test_a_profile_prop5_of_another_length_is_not_used_as_pack_quantities(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;

        $stats = $this->import([
            $this->row('ZB2', 'b_al', ['Prop5' => '50'], "ZB2-A{$sep}ZB2-B"),
            $this->row('ZB4', 'b_al', ['Prop5' => "6{$sep}12"], "ZB4-A{$sep}ZB4-B{$sep}ZB4-C"),
            // باركود واحد ورقم واحد: معنى الرقم غير محسوم، فلا يُعدّ كمية عبوة مهملة.
            $this->row('ZB5', 'b_al', ['Prop5' => '50'], 'ZB5-A'),
        ]);

        foreach (['ZB2-B', 'ZB4-B', 'ZB4-C'] as $barcode) {
            $this->assertSame('1.000000', ProductBarcode::query()->where('barcode', $barcode)->value('pack_quantity'));
        }

        $this->assertNull($this->productBySku('ZB2')->bundle_count);
        $this->assertSame('50', $this->productBySku('ZB2')->meta_data['props']['Prop5']);
        $this->assertSame(0, $stats['first_barcode_pack_quantity_ignored']);
    }

    public function test_the_same_barcode_on_two_items_imports_fine(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;

        // نفس باركود المقطع تحت كل لون، رئيسياً واضافياً.
        $stats = $this->import([
            $this->row('ZD1', 'b_al', [], 'ZD-SHARED'),
            $this->row('ZD2', 'b_al', [], "ZD2-A{$sep}ZD-SHARED"),
            $this->row('ZD3', 'b_al', [], "ZD3-A{$sep}ZD-SHARED"),
            $this->row('ZD4', 'b_al', [], 'ZD-SHARED'),
        ]);

        $this->assertSame(2, $stats['barcode_rows_created']);
        $this->assertSame('ZD-SHARED', $this->productBySku('ZD1')->barcode);
        $this->assertSame('ZD-SHARED', $this->productBySku('ZD4')->barcode);
        $this->assertSame('ZD2-A', $this->productBySku('ZD2')->barcode);
        $this->assertSame(
            [$this->productBySku('ZD2')->id, $this->productBySku('ZD3')->id],
            ProductBarcode::query()->where('barcode', 'ZD-SHARED')->orderBy('product_id')->pluck('product_id')->all()
        );
    }

    public function test_importing_again_keeps_manual_edits_and_does_not_duplicate_barcodes(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;
        $rows = [$this->row('ZE1', 'b_al', ['Prop1' => '1.2', 'Prop3' => 'OLD'], "ZE1-A{$sep}ZE1-B")];

        $this->import($rows);

        DB::table('items_products')->where('sku', 'ZE1')->update(['weight_per_meter' => 9.5, 'shelf' => 'MANUAL', 'weight' => 7]);

        $this->import($rows);

        $product = $this->productBySku('ZE1');

        $this->assertSame('9.5000', $product->weight_per_meter);
        $this->assertSame('MANUAL', $product->shelf);
        $this->assertSame('7.00', (string) $product->weight);
        $this->assertSame(1, ProductBarcode::query()->where('product_id', $product->id)->count());
    }

    private function productBySku(string $sku): Product
    {
        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    /**
     * صف من جدول items القديم بأعمدته الـ 24 وبترتيبها.
     *
     * @param  array<string, string>  $props
     * @param  array{name?: string, unit?: string, parent?: string, active?: string}  $extra
     * @return array<int, string|null>
     */
    private function row(string $id, string $type, array $props, ?string $barcode = null, array $extra = []): array
    {
        $this->skus[] = $id;

        return [
            $id, $extra['name'] ?? 'صنف '.$id, $barcode, $type, '10', $extra['unit'] ?? null, $extra['parent'] ?? '0', null, null, null, null, null, null, '3',
            $props['Prop1'] ?? null, $props['Prop2'] ?? null, $props['Prop3'] ?? null, $props['Prop4'] ?? null, $props['Prop5'] ?? null,
            $props['Prop6'] ?? null, null, null, null, $extra['active'] ?? 'Y',
        ];
    }

    /**
     * @param  array<int, array<int, string|null>>  $rows
     * @return array<string, mixed>
     */
    private function import(array $rows): array
    {
        return $this->importTuples(array_map(fn (array $row): string => '('.implode(', ', array_map(
            fn ($value): string => $value === null ? 'NULL' : "'".str_replace("'", "''", $value)."'",
            $row
        )).')', $rows));
    }

    /**
     * @param  array<int, string>  $tuples  صفوف SQL كما في ملف التصدير
     * @return array<string, mixed>
     */
    private function importTuples(array $tuples): array
    {
        $this->dump ??= tempnam(sys_get_temp_dir(), 'items');
        file_put_contents($this->dump, "INSERT INTO `items` VALUES\n".implode(",\n", $tuples).";\n");

        return app(LegacyItemsImporter::class)->import($this->dump);
    }
}
