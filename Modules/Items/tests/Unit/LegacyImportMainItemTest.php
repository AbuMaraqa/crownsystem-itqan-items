<?php

namespace Modules\Items\tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;
use Modules\Items\Services\LegacyItemsImporter;
use Modules\Items\Services\MainProductLinker;
use Modules\Items\Support\Search\SearchCapabilities;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * الصنف الرئيسي a_al صنف حقيقي من اول استيراد، بتوأم فئة يبقي شكل الشجرة كما هو؛
 * و c_cont فئة دائماً؛ و b_al تحت فئة مباشرة يبقى كما كان.
 */
class LegacyImportMainItemTest extends TestCase
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

    public function test_a_main_item_with_children_is_a_real_product_in_the_same_run(): void
    {
        $this->import($this->sampleTree());

        $main = Product::query()->with(['productBarcodes', 'unit.translations'])->where('sku', 'M1')->firstOrFail();

        $this->assertSame('weight', $main->pricing_type);
        $this->assertSame(25.5, (float) $main->cost_price);
        $this->assertSame('MAIN-1', $main->barcode);
        $this->assertSame(['MAIN-2'], $main->productBarcodes->pluck('barcode')->all());
        $this->assertSame('كغم', $main->unit?->name);
        $this->assertSame('S-9', $main->shelf);
        $this->assertSame('ملاحظة الرئيسي', $main->description);
        $this->assertSame(0, (int) $main->is_active);
        $this->assertSame('المنيوم لون ايرون', $main->name);
        $this->assertSame('a_al', $main->meta_data['legacy_item_type']);
    }

    public function test_the_twin_category_keeps_the_tree_unchanged(): void
    {
        $this->import($this->sampleTree());

        $twin = Category::query()->where('code', 'M1')->firstOrFail();
        $root = Category::query()->where('code', 'C1')->firstOrFail();
        $main = Product::query()->where('sku', 'M1')->firstOrFail();

        $this->assertSame((int) $root->id, (int) $twin->parent_id);
        // في تصنيفه التوأم فقط، كما يفعل createMainFromCategory.
        $this->assertSame([(int) $twin->id], $main->categories()->pluck((new Category)->getTable().'.id')->map(fn ($id): int => (int) $id)->all());

        app(MainProductLinker::class)->link(dryRun: false, createFromCategories: true);

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('Product View List', 'web'));
        $this->actingAs($user);

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $rows = $this->getJson(route('products.tree.rows', ['category' => $twin->id]))->assertOk()->json('rows');

        // العقدة صف واحد برأس الصنف، وتحته مقاطعها.
        $this->assertSame(['M1', 'M1001', 'M1002'], array_column($rows, 'sku'));
        $this->assertSame('product', $rows[0]['kind']);
    }

    public function test_the_linker_finds_the_main_item_by_sku_and_creates_nothing_for_it(): void
    {
        $this->import($this->sampleTree());

        $mainId = (int) Product::query()->where('sku', 'M1')->value('id');
        $report = app(MainProductLinker::class)->link(dryRun: false, createFromCategories: true);

        $this->assertSame(1, Product::query()->where('sku', 'M1')->count());
        $this->assertSame($mainId, (int) Product::query()->where('sku', 'M1001')->value('main_product_id'));
        $this->assertSame($mainId, (int) Product::query()->where('sku', 'M1002')->value('main_product_id'));
        // الرئيسي نفسه ليس مقطعاً تحت فئته، ولا يُنشأ من فئته صنف.
        $this->assertNull(Product::query()->whereKey($mainId)->value('main_product_id'));
        $this->assertFalse(Product::query()->where('sku', 'C1')->exists());
        $this->assertSame(0, $report['stats']['main_products_created']);
    }

    public function test_a_main_item_without_children_stays_a_plain_product_under_its_category(): void
    {
        $this->import([
            $this->row('C1', 'c_cont', 'فئة', ['parent' => '0']),
            $this->row('M2', 'a_al', 'رئيسي بلا مقاطع', ['parent' => 'C1']),
            $this->row('A1', 'a_store', 'صنف في الفئة', ['parent' => 'C1']),
        ]);

        app(MainProductLinker::class)->link(dryRun: false, createFromCategories: true);

        $main = Product::query()->where('sku', 'M2')->firstOrFail();

        $this->assertFalse(Category::query()->where('code', 'M2')->exists());
        $this->assertNull($main->main_product_id);
        $this->assertSame(['C1'], $main->categories()->pluck('code')->all());
        $this->assertFalse(Product::query()->where('sku', 'C1')->exists());
    }

    public function test_a_piece_directly_under_a_category_still_gets_a_main_item_from_the_category(): void
    {
        $this->import([
            $this->row('C2', 'c_cont', 'فئة مقاطع', ['parent' => '0']),
            $this->row('P1', 'b_al', 'مقطع مباشر', ['parent' => 'C2', 'Prop1' => '1.2', 'Prop2' => '6']),
        ]);

        $this->assertFalse(Product::query()->where('sku', 'C2')->exists());

        $report = app(MainProductLinker::class)->link(dryRun: false, createFromCategories: true);
        $main = Product::query()->where('sku', 'C2')->firstOrFail();

        $this->assertSame(1, $report['stats']['main_products_created']);
        $this->assertSame('فئة مقاطع', $main->name);
        $this->assertSame((int) $main->id, (int) Product::query()->where('sku', 'P1')->value('main_product_id'));
    }

    public function test_a_category_row_is_always_a_category_even_without_children(): void
    {
        $this->import([
            $this->row('C3', 'c_cont', 'فئة فارغة', ['parent' => '0']),
            $this->row('C4', 'c_cont', 'فئة فرعية فارغة', ['parent' => 'C3']),
        ]);

        $this->assertFalse(Product::query()->whereIn('sku', ['C3', 'C4'])->exists());
        $this->assertSame((int) Category::query()->where('code', 'C3')->value('id'), (int) Category::query()->where('code', 'C4')->value('parent_id'));
    }

    public function test_the_tree_root_row_stays_out_of_categories_and_products(): void
    {
        $this->import([
            $this->row('0', 'c_cont', 'شجرة الاصناف', ['parent' => '']),
            $this->row('C5', 'c_cont', 'فئة', ['parent' => '0']),
        ]);

        $this->assertFalse(Category::query()->where('code', '0')->exists());
        $this->assertFalse(Product::query()->where('sku', '0')->exists());
        $this->assertNull(Category::query()->where('code', 'C5')->value('parent_id'));
    }

    public function test_importing_over_a_main_item_the_linker_created_fills_it_without_duplicating(): void
    {
        $rows = $this->sampleTree();

        $this->import($rows);
        app(MainProductLinker::class)->link(dryRun: false, createFromCategories: true);
        $mainId = (int) Product::query()->where('sku', 'M1')->value('id');
        Product::query()->whereKey($mainId)->update(['cost_price' => 0]);

        $this->import($rows);

        $this->assertSame(1, Product::query()->where('sku', 'M1')->count());
        $this->assertSame($mainId, (int) Product::query()->where('sku', 'M1')->value('id'));
        $this->assertSame(25.5, (float) Product::query()->whereKey($mainId)->value('cost_price'));
        $this->assertSame(1, ProductBarcode::query()->where('product_id', $mainId)->count());
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    private function sampleTree(): array
    {
        return [
            $this->row('C1', 'c_cont', 'المنيوم', ['parent' => '0']),
            $this->row('M1', 'a_al', 'المنيوم لون ايرون', [
                'parent' => 'C1', 'cost' => '25.5', 'barcode' => 'MAIN-1█MAIN-2', 'unit' => 'كغم',
                'note' => 'ملاحظة الرئيسي', 'Prop1' => 'S-9', 'active' => 'N',
            ]),
            $this->row('M1001', 'b_al', 'مقطع 1', ['parent' => 'M1', 'Prop1' => '1.2', 'Prop2' => '6']),
            $this->row('M1002', 'b_al', 'مقطع 2', ['parent' => 'M1', 'Prop1' => '0.7', 'Prop2' => '6']),
        ];
    }

    /**
     * @param  array<string, string|null>  $extra
     * @return array<int, string|null>
     */
    private function row(string $id, string $type, string $name, array $extra = []): array
    {
        $this->skus[] = $id;

        return [
            $id, $name, $extra['barcode'] ?? null, $type, $extra['cost'] ?? '0', $extra['unit'] ?? null, $extra['parent'] ?? '0', null, null, null, null, null, $extra['note'] ?? null, '3',
            $extra['Prop1'] ?? null, $extra['Prop2'] ?? null, null, null, null,
            null, null, null, null, $extra['active'] ?? 'Y',
        ];
    }

    /**
     * @param  array<int, array<int, string|null>>  $rows
     */
    private function import(array $rows): void
    {
        $tuples = array_map(fn (array $row): string => '('.implode(', ', array_map(
            fn ($value): string => $value === null ? 'NULL' : "'".str_replace("'", "''", $value)."'",
            $row
        )).')', $rows);

        $this->dump ??= tempnam(sys_get_temp_dir(), 'items');
        file_put_contents($this->dump, "INSERT INTO `items` VALUES\n".implode(",\n", $tuples).";\n");

        app(LegacyItemsImporter::class)->import($this->dump);
    }
}
