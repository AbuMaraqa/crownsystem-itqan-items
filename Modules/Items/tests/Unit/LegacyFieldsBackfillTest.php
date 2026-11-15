<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;
use Modules\Items\Services\LegacyFieldsBackfill;
use Modules\Items\Support\LegacyItemFields;
use Modules\Items\Support\ProductSearch;
use Modules\Items\Support\Search\SearchCapabilities;
use Tests\TestCase;

/**
 * تصحيح ما استورده المستورد القديم من meta_data: يملأ الفارغ فقط، ولا يمس تعديلاً
 * يدوياً، ولا يكتب شيئاً في التشغيل التجريبي.
 */
class LegacyFieldsBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SearchCapabilities::forget();
        SearchCapabilities::withoutFullText();
    }

    /**
     * صنف كما تركه المستورد القديم: Prop1 في weight بمنزلتين، والباركود نص واحد.
     *
     * @param  array<string, string|null>  $props
     * @param  array<string, mixed>  $attributes
     */
    private function imported(string $type, array $props, ?string $barcode = null, array $attributes = []): Product
    {
        $prop1 = $props['Prop1'] ?? null;

        $product = new Product(array_merge([
            'slug' => 'old-'.Str::lower(Str::random(8)),
            'sku' => 'OLD'.Str::upper(Str::random(6)),
            'base_price' => 0,
            'barcode' => $barcode,
            'weight' => is_numeric($prop1) ? number_format((float) $prop1, 2, '.', '') : null,
            'meta_data' => [
                'legacy_item_type' => $type,
                'legacy_barcode' => $barcode,
                'props' => $props,
            ],
        ], $attributes));
        $product->save();
        $product->translateOrNew('ar')->name = 'صنف '.$product->sku;
        $product->save();

        return $product;
    }

    private function run_backfill(bool $apply = true): array
    {
        return app(LegacyFieldsBackfill::class)->run($apply);
    }

    public function test_it_fills_empty_fields_per_type(): void
    {
        $profile = $this->imported('b_al', ['Prop1' => '0.444', 'Prop2' => '6', 'Prop3' => 'A7', 'Prop5' => '25']);
        $store = $this->imported('a_store', ['Prop1' => 'B-4']);
        $area = $this->imported('a_storeArea', ['Prop1' => 'C1', 'Prop2' => '2.5']);

        $stats = $this->run_backfill();

        $profile->refresh();
        $this->assertSame('0.4440', $profile->weight_per_meter);
        $this->assertSame('6.0000', $profile->length);
        $this->assertSame('A7', $profile->shelf);
        // معنى Prop5 في b_al لم يُحسم بعد: لا يُملأ منه عدد الرابطة.
        $this->assertNull($profile->bundle_count);

        $this->assertSame('B-4', $store->refresh()->shelf);
        $this->assertSame('C1', $area->refresh()->shelf);
        $this->assertSame('2.5000', $area->length);

        $this->assertSame(3, $stats['products_changed']);
        $this->assertSame(1, $stats['weight_per_meter_filled']);
        $this->assertSame(2, $stats['length_filled']);
        $this->assertSame(3, $stats['shelf_filled']);
    }

    public function test_a_picture_row_maps_like_a_profile(): void
    {
        $picture = $this->imported('a_storePicture', ['Prop1' => '1.25', 'Prop2' => '6', 'Prop3' => 'R-3']);
        $text = $this->imported('a_storePicture', ['Prop1' => 'A7', 'Prop2' => 'x']);

        $stats = $this->run_backfill();

        $picture->refresh();
        $this->assertSame('1.2500', $picture->weight_per_meter);
        $this->assertSame('6.0000', $picture->length);
        $this->assertSame('R-3', $picture->shelf);
        // Prop1 هنا وزن متر، فلا يُعدّ وزناً منقولاً خطأً.
        $this->assertSame('1.25', (string) $picture->weight);
        $this->assertSame('simple', $picture->pricing_type);

        $text->refresh();
        $this->assertNull($text->weight_per_meter);
        $this->assertNull($text->length);
        $this->assertNull($text->shelf);

        $this->assertSame(0, $stats['weight_cleared']);
    }

    public function test_zero_is_not_filled_into_measures_or_the_shelf(): void
    {
        $profile = $this->imported('b_al', ['Prop1' => '0.00', 'Prop2' => '0', 'Prop3' => '0']);
        $main = $this->imported('a_al', ['Prop1' => '0']);

        $this->run_backfill();

        $this->assertNull($profile->refresh()->weight_per_meter);
        $this->assertNull($profile->length);
        $this->assertNull($profile->shelf);
        $this->assertNull($main->refresh()->shelf);
    }

    public function test_it_fills_an_empty_unit_and_keeps_a_chosen_one(): void
    {
        $meta = ['legacy_item_type' => 'b_al', 'unit' => 'عامود', 'props' => []];
        $empty = $this->imported('b_al', [], null, ['meta_data' => $meta]);
        $sameUnit = $this->imported('b_al', [], null, ['meta_data' => $meta]);

        $chosenUnit = DB::table('items_units')->insertGetId(['slug' => 'zz-chosen-unit']);
        $chosen = $this->imported('b_al', [], null, ['meta_data' => $meta, 'unit_id' => $chosenUnit]);

        $dry = $this->run_backfill(apply: false);

        $this->assertSame(2, $dry['unit_id_filled']);
        $this->assertNull($empty->refresh()->unit_id);
        $this->assertFalse(DB::table('items_units')->where('slug', 'like', 'legacy-unit-%')->exists());

        $applied = $this->run_backfill();

        $this->assertSame(2, $applied['unit_id_filled']);
        $this->assertSame('عامود', $empty->refresh()->unit->translate('ar')->name);
        $this->assertSame($empty->unit_id, $sameUnit->refresh()->unit_id);
        $this->assertSame($chosenUnit, $chosen->refresh()->unit_id);
    }

    public function test_it_adds_a_missing_english_name_unless_prop6_is_a_number(): void
    {
        $named = $this->imported('a_store', ['Prop6' => 'Door hinge']);
        $numeric = $this->imported('b_al', ['Prop6' => '1.267']);
        $edited = $this->imported('a_store', ['Prop6' => 'Old name']);
        $edited->translateOrNew('en')->name = 'Edited';
        $edited->save();

        $dry = $this->run_backfill(apply: false);

        $this->assertSame(1, $dry['english_names_created']);
        $this->assertNull($named->refresh()->translate('en'));

        $applied = $this->run_backfill();

        $this->assertSame(1, $applied['english_names_created']);
        $this->assertSame('Door hinge', $named->refresh()->translate('en')->name);
        $this->assertNull($numeric->refresh()->translate('en'));
        $this->assertSame('Edited', $edited->refresh()->translate('en')->name);
        $this->assertSame(0, $this->run_backfill()['english_names_created']);
    }

    public function test_it_never_overwrites_manual_edits(): void
    {
        $profile = $this->imported('b_al', ['Prop1' => '0.444', 'Prop2' => '6', 'Prop3' => 'A7', 'Prop5' => '25'], null, [
            'weight_per_meter' => 1.5,
            'length' => 4,
            'shelf' => 'MANUAL',
            'bundle_count' => 10,
        ]);

        $stats = $this->run_backfill();

        $profile->refresh();
        $this->assertSame('1.5000', $profile->weight_per_meter);
        $this->assertSame('4.0000', $profile->length);
        $this->assertSame('MANUAL', $profile->shelf);
        $this->assertSame(10, (int) $profile->bundle_count);
        $this->assertSame(3, $stats['fields_kept_existing']);
        $this->assertSame(0, $stats['products_changed']);
    }

    public function test_dry_run_writes_nothing_and_reports_the_same_counts(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;
        $store = $this->imported('a_store', ['Prop1' => '7', 'Prop5' => "1{$sep}12"], "DRY-A{$sep}DRY-B");
        $before = DB::table('items_products')->where('id', $store->id)->first();

        $dry = $this->run_backfill(apply: false);

        $this->assertTrue($dry['dry_run']);
        $this->assertEquals($before, DB::table('items_products')->where('id', $store->id)->first());
        $this->assertSame(0, ProductBarcode::query()->count());

        $applied = $this->run_backfill();

        $this->assertFalse($applied['dry_run']);
        foreach (['products_changed', 'shelf_filled', 'weight_cleared', 'primary_barcode_fixed', 'barcode_rows_created'] as $key) {
            $this->assertGreaterThan(0, $dry[$key], $key);
            $this->assertSame($dry[$key], $applied[$key], $key);
        }
    }

    public function test_weight_is_cleared_only_when_it_is_the_wrongly_mapped_prop1(): void
    {
        $wrong = $this->imported('a_store', ['Prop1' => '12']);
        $edited = $this->imported('a_store', ['Prop1' => '12'], null, ['weight' => 3.5]);
        $main = $this->imported('a_al', ['Prop1' => '8']);
        // Prop1 هنا وزن فعلاً.
        $withUnit = $this->imported('a_withUnit', ['Prop1' => '5']);
        // وزن المتر وزن؛ النوع لا يُعدّ صفاً غير موزون.
        $profile = $this->imported('b_al', ['Prop1' => '1.2']);
        // غير مؤكد المعنى: يبقى كما هو.
        $unknown = $this->imported('a_Shoes', ['Prop1' => '40']);

        $stats = $this->run_backfill();

        $this->assertNull($wrong->refresh()->weight);
        $this->assertSame('12', $wrong->shelf);
        $this->assertSame('3.50', (string) $edited->refresh()->weight);
        $this->assertNull($main->refresh()->weight);
        $this->assertSame('5.00', (string) $withUnit->refresh()->weight);
        $this->assertSame('1.20', (string) $profile->refresh()->weight);
        $this->assertSame('40.00', (string) $unknown->refresh()->weight);

        $this->assertSame(2, $stats['weight_cleared']);
        $this->assertSame(1, $stats['weight_kept_edited']);
    }

    public function test_it_splits_the_imported_barcode_string(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;
        $product = $this->imported('a_store', ['Prop5' => "1{$sep}12"], "SPLIT-A{$sep}SPLIT-B");

        $stats = $this->run_backfill();

        $this->assertSame('SPLIT-A', $product->refresh()->barcode);
        $this->assertSame('12.000000', ProductBarcode::query()->where('barcode', 'SPLIT-B')->value('pack_quantity'));
        $this->assertSame(1, $stats['primary_barcode_fixed']);
        $this->assertSame(1, $stats['barcode_rows_created']);

        // الفهرس يُحدَّث لما تغيّر، فيُعثر عليه بباركوده الاضافي.
        $this->assertContains($product->id, ProductSearch::find('SPLIT-B')->ids);
    }

    public function test_a_manually_changed_barcode_stays_and_its_old_barcodes_are_not_added(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;
        $product = $this->imported('a_store', [], "OLD-A{$sep}OLD-B");
        $product->update(['barcode' => 'NEW-MANUAL']);

        $stats = $this->run_backfill();

        $this->assertSame('NEW-MANUAL', $product->refresh()->barcode);
        $this->assertSame(0, ProductBarcode::query()->count());
        $this->assertSame(1, $stats['barcode_kept_edited']);
    }

    public function test_an_empty_barcode_is_filled(): void
    {
        $product = $this->imported('a_store', [], 'FILL-A');
        $product->update(['barcode' => null]);

        $this->run_backfill();

        $this->assertSame('FILL-A', $product->refresh()->barcode);
    }

    public function test_the_same_barcode_on_two_items_is_repaired_on_both(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;
        $main = $this->imported('b_al', [], 'SHARED');
        $first = $this->imported('b_al', [], "FIRST{$sep}SHARED");
        $second = $this->imported('b_al', [], "SECOND{$sep}SHARED");

        $stats = $this->run_backfill();

        $this->assertSame('SHARED', $main->refresh()->barcode);
        $this->assertSame('FIRST', $first->refresh()->barcode);
        $this->assertSame('SECOND', $second->refresh()->barcode);
        $this->assertSame(2, $stats['barcode_rows_created']);
        $this->assertSame(
            [$first->id, $second->id],
            ProductBarcode::query()->where('barcode', 'SHARED')->orderBy('product_id')->pluck('product_id')->all()
        );
    }

    public function test_barcode_positions_are_kept_in_sort_order(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;
        $product = $this->imported('b_al', [], "POS-0{$sep}POS-1{$sep}POS-2");

        $this->run_backfill();

        $this->assertSame('POS-0', $product->refresh()->barcode);
        $this->assertSame(
            ['POS-1' => 1, 'POS-2' => 2],
            ProductBarcode::query()->where('product_id', $product->id)->orderBy('sort_order')->pluck('sort_order', 'barcode')->all()
        );
    }

    public function test_running_twice_changes_nothing_more(): void
    {
        $sep = LegacyItemFields::BARCODE_SEPARATOR;
        $this->imported('a_store', ['Prop1' => '3'], "TWICE-A{$sep}TWICE-B");

        $this->run_backfill();
        $second = $this->run_backfill();

        $this->assertSame(0, $second['products_changed']);
        $this->assertSame(0, $second['barcode_rows_created']);
        $this->assertSame(1, ProductBarcode::query()->count());
    }

    public function test_products_without_legacy_data_are_skipped(): void
    {
        $product = new Product(['slug' => 'manual-'.Str::random(6), 'base_price' => 0, 'weight' => 9, 'meta_data' => ['note' => 'x']]);
        $product->save();

        $stats = $this->run_backfill();

        $this->assertSame(1, $stats['not_legacy']);
        $this->assertSame('9.00', (string) $product->refresh()->weight);
    }

    public function test_the_command_is_a_dry_run_unless_apply_is_passed(): void
    {
        $store = $this->imported('a_store', ['Prop1' => 'R1']);

        $this->artisan('items:backfill-legacy-fields')
            ->expectsOutputToContain('Dry run only')
            ->assertSuccessful();

        $this->assertNull($store->refresh()->shelf);

        $this->artisan('items:backfill-legacy-fields', ['--apply' => true])->assertSuccessful();

        $this->assertSame('R1', $store->refresh()->shelf);
    }
}
