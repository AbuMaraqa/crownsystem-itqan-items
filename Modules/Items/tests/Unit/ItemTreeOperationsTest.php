<?php

namespace Modules\Items\tests\Unit;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;
use Modules\Items\Services\ItemTreeService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * عمليات الشجرة: القائمة حسب النوع، النقل، نسخ الرئيسي بمقاطعه، الحذف، اضافة المقطع،
 * وتطبيق المقطع على الالوان الاخرى. الصفحة عبر مسارات شجرة الاصناف في لوحة Vue.
 */
class ItemTreeOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['Product View List', 'Product Create', 'Product Update', 'Product Delete', 'Category Create', 'Category Update', 'Category Delete', 'Stock Item Card View', 'Stock View List'];

    private ItemTreeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ItemTreeService::class);
        $this->withoutMiddleware([\Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class, \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class]);
        $this->actAs(self::ALL);
    }

    /**
     * حفظ بطاقة الصنف كما يرسلها النموذج.
     */
    private function saveCard(array $data, ?int $productId = null): TestResponse
    {
        $data = ['item_type' => 'goods', 'pricing_type' => 'simple', 'base_price' => 0, ...$data];

        return $this->from(route('products.tree'))->{$productId ? 'put' : 'post'}(
            $productId ? route('products.tree.items.update', $productId) : route('products.tree.items.store'),
            $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cardState(int $productId): array
    {
        return $this->getJson(route('products.tree.items.edit', $productId))->assertOk()->json('state');
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeMenu(string $kind, ?int $id = null): array
    {
        return $this->getJson(route('products.tree.actions', ['kind' => $kind, 'id' => $id]))->assertOk()->json();
    }

    private function actAs(array $permissions): void
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $this->actingAs($user);
    }

    private function category(string $code, ?Category $parent = null): Category
    {
        $category = new Category(['slug' => 'cat-'.Str::random(8), 'code' => $code, 'status' => 1, 'parent_id' => $parent?->id]);
        $category->save();
        $category->translateOrNew(app()->getLocale())->name = 'فئة '.$code;
        $category->save();

        return $category;
    }

    private function product(string $sku, string $type = 'simple', ?Product $main = null, ?Category $category = null, array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'slug' => 'p-'.Str::random(8),
            'sku' => $sku,
            'base_price' => 3,
            'pricing_type' => $type,
            'main_product_id' => $main?->id,
        ], $attributes));
        $product->save();
        $product->translateOrNew(app()->getLocale())->name = $attributes['name'] ?? 'صنف '.$sku;
        $product->save();

        if ($category) {
            $product->categories()->sync([$category->id]);
        }

        return $product;
    }

    /**
     * @return array{0: Category, 1: Product, 2: Product}
     */
    private function twinMain(string $code, ?Category $parent = null): array
    {
        $twin = $this->category($code, $parent);
        $main = $this->product($code, 'weight', null, $twin);
        $piece = $this->product($code.'001', 'weight', $main, $twin, ['barcode' => 'B-'.$code]);

        return [$twin, $main, $piece];
    }

    private function actionKeys(string $kind, ?int $id = null): array
    {
        return array_column($this->nodeMenu($kind, $id)['actions'], 'key');
    }

    public function test_the_menu_follows_the_node_type(): void
    {
        $empty = $this->category('00001');
        $full = $this->category('00002');
        [$twin, $main, $piece] = $this->twinMain('00003');
        $item = $this->product('00002001', 'simple', null, $full);
        $plainMain = $this->product('00002002', 'weight', null, $full);

        $this->assertSame(['add_category', 'add_item'], $this->actionKeys('root'));
        $this->assertSame(['edit', 'add_category', 'add_item', 'delete', 'move', 'stock_report'], $this->actionKeys('category', $empty->id));
        $this->assertSame(['edit', 'add_category', 'add_item', 'move', 'stock_report'], $this->actionKeys('category', $full->id));

        $mainActions = ['edit', 'add_piece', 'copy', 'delete', 'movement'];
        $this->assertSame($mainActions, $this->actionKeys('category', $twin->id));
        $this->assertSame($mainActions, $this->actionKeys('product', $main->id));
        $this->assertSame($mainActions, $this->actionKeys('product', $plainMain->id));
        $this->assertSame(['edit', 'delete', 'movement'], $this->actionKeys('product', $piece->id));
        $this->assertSame(['edit', 'delete', 'move', 'movement'], $this->actionKeys('product', $item->id));
    }

    public function test_the_menu_and_every_action_are_gated_by_permission(): void
    {
        $category = $this->category('00001');
        $item = $this->product('00001001', 'simple', null, $category);
        [, $main] = $this->twinMain('00002');

        $this->actAs(['Product View List']);

        $this->assertSame([], $this->actionKeys('product', $item->id));
        $this->assertSame([], $this->actionKeys('root'));

        $this->delete(route('products.tree.items.destroy', $item->id))->assertForbidden();
        $this->delete(route('products.tree.categories.destroy', $category->id))->assertForbidden();
        $this->post(route('products.tree.items.copy', $main->id))->assertForbidden();
        $this->put(route('products.tree.items.move', $item->id), ['target_id' => $category->id])->assertForbidden();
        $this->put(route('products.tree.categories.move', $category->id), ['target_id' => 0])->assertForbidden();
        $this->getJson(route('products.tree.items.create', ['fixed_main' => $main->id]))->assertForbidden();
        $this->getJson(route('products.tree.items.create', ['category' => $category->id]))->assertForbidden();
        $this->post(route('products.tree.categories.store'), ['name' => 'ممنوع', 'parent_id' => $category->id])->assertForbidden();

        $this->assertNotNull($item->fresh());
        $this->assertNotNull($category->fresh());
        $this->assertSame(3, Product::query()->count());
    }

    public function test_an_action_outside_the_node_type_is_refused_on_the_server(): void
    {
        [$twin, $main, $piece] = $this->twinMain('00002');
        $target = $this->category('00009');

        $this->put(route('products.tree.items.move', $main->id), ['target_id' => $target->id])->assertForbidden();
        $this->put(route('products.tree.items.move', $piece->id), ['target_id' => $target->id])->assertForbidden();
        $this->put(route('products.tree.categories.move', $twin->id), ['target_id' => $target->id])->assertForbidden();
        $this->post(route('products.tree.items.copy', $piece->id))->assertForbidden();

        $this->assertSame('00002', $main->fresh()->sku);
        $this->assertSame(2, Product::query()->count());
    }

    public function test_moving_an_item_gives_it_a_new_code_under_the_target(): void
    {
        $from = $this->category('00001');
        $to = $this->category('00002');
        $this->product('00002001', 'simple', null, $to);
        $item = $this->product('00001001', 'simple', null, $from);

        $this->assertContains($to->id, array_column($this->getJson(route('products.tree.move-targets', ['q' => '00002']))->json(), 'value'));

        $this->from(route('products.tree'))
            ->put(route('products.tree.items.move', $item->id), ['target_id' => $to->id])
            ->assertSessionHas('success');

        $item->refresh();
        $this->assertSame('00002002', $item->sku);
        $this->assertSame([(int) $to->id], $item->categories()->pluck((new Category)->getTable().'.id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_moving_a_category_recodes_everything_under_it_keeping_twins_in_step(): void
    {
        $root = $this->category('00001');
        $moved = $this->category('00001001', $root);
        $child = $this->category('00001001001', $moved);
        [$twin, $main, $piece] = $this->twinMain('00001001002', $moved);
        $item = $this->product('00001001003', 'simple', null, $moved);
        $target = $this->category('00002');

        $this->service->moveCategory($moved, $target);

        $this->assertSame('00002001', $moved->fresh()->code);
        $this->assertSame((int) $target->id, (int) $moved->fresh()->parent_id);
        $this->assertStringStartsWith('00002001', $child->fresh()->code);
        $this->assertStringStartsWith('00002001', $twin->fresh()->code);
        // التوأم يبقى برقم فئته، فتبقى العقدة واحدة، ومقطعه تحت رقمه الجديد.
        $this->assertSame($twin->fresh()->code, $main->fresh()->sku);
        $this->assertStringStartsWith($main->fresh()->sku, $piece->fresh()->sku);
        $this->assertSame((int) $main->id, (int) $piece->fresh()->main_product_id);
        $this->assertStringStartsWith('00002001', $item->fresh()->sku);
        $this->assertNotNull($this->service->twinOf($twin->fresh()));
    }

    public function test_moving_a_category_to_the_root_uses_a_root_code(): void
    {
        $root = $this->category('00001');
        $moved = $this->category('00001001', $root);

        // بلا وجهة: يُطلب اختيارها ولا يتغير شيء.
        $this->from(route('products.tree'))
            ->put(route('products.tree.categories.move', $moved->id), [])
            ->assertSessionHas('error', __('Choose the destination category.'));
        $this->assertSame((int) $root->id, (int) $moved->fresh()->parent_id);

        $this->from(route('products.tree'))
            ->put(route('products.tree.categories.move', $moved->id), ['target_id' => 0])
            ->assertSessionHas('success');

        $this->assertNull($moved->fresh()->parent_id);
        $this->assertSame(5, strlen($moved->fresh()->code));
    }

    public function test_move_refusals(): void
    {
        $root = $this->category('00001');
        $child = $this->category('00001001', $root);
        [$twin, $main, $piece] = $this->twinMain('00002');

        $refused = [
            fn () => $this->service->moveCategory($root, $child),
            fn () => $this->service->moveCategory($root, $root),
            fn () => $this->service->moveCategory($twin, $root),
            fn () => $this->service->moveProduct($main, $root),
            fn () => $this->service->moveProduct($piece, $root),
            fn () => $this->service->moveProduct($this->product('X1', 'simple', null, $root), $twin),
            fn () => $this->service->moveCategory($child, $root),
        ];

        foreach ($refused as $index => $action) {
            try {
                $action();
                $this->fail("Move #{$index} should be refused.");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('00001', $root->fresh()->code);
        $this->assertSame('00002', $main->fresh()->sku);
    }

    public function test_a_failed_move_rolls_back_when_the_target_is_full(): void
    {
        $from = $this->category('00001');
        $moved = $this->category('00001001', $from);
        $target = $this->category('00002');
        $this->category('00002999', $target);

        try {
            $this->service->moveCategory($moved, $target);
            $this->fail('A full target should refuse the move.');
        } catch (DomainException) {
            $this->assertSame('00001001', $moved->fresh()->code);
            $this->assertSame((int) $from->id, (int) $moved->fresh()->parent_id);
        }
    }

    public function test_copying_a_main_item_copies_pieces_and_barcodes_without_stock(): void
    {
        $parent = $this->category('00001');
        [$twin, $main, $piece] = $this->twinMain('00001001', $parent);
        ProductBarcode::query()->create(['product_id' => $piece->id, 'barcode' => 'EXTRA', 'pack_quantity' => 12, 'is_prefix' => false, 'sort_order' => 1]);
        DB::table($main->getTable())->where('id', $piece->id)->update(['stock_quantity' => 50, 'stock_weight' => 90]);

        // الفئة التوأم عقدة صنف رئيسي: قائمتها تعطي معرّف الصنف، والنسخ عليه.
        $menu = $this->nodeMenu('category', $twin->id);
        $this->assertSame('main', $menu['type']);
        $this->assertSame((int) $main->id, (int) $menu['product_id']);

        $this->from(route('products.tree'))
            ->post(route('products.tree.items.copy', $menu['product_id']))
            ->assertSessionHas('success');

        $copy = Product::query()->whereNull('main_product_id')->whereKeyNot($main->id)->where('sku', 'like', '00001%')->firstOrFail();
        $copyPiece = Product::query()->where('main_product_id', $copy->id)->firstOrFail();

        $this->assertNotSame($main->sku, $copy->sku);
        $this->assertSame('weight', $copy->pricing_type);
        $this->assertStringStartsWith($copy->sku, $copyPiece->sku);
        $this->assertSame($piece->barcode, $copyPiece->barcode);
        $this->assertSame(['EXTRA'], ProductBarcode::query()->where('product_id', $copyPiece->id)->pluck('barcode')->all());
        $this->assertSame(0.0, (float) $copyPiece->stock_quantity);
        $this->assertSame(0.0, (float) $copyPiece->stock_weight);
        $this->assertSame($piece->name, $copyPiece->name);
        // اسم الاصل لا يتغير، ونسخة الرئيسي مميَّزة.
        $this->assertSame('صنف 00001001', $main->fresh()->name);
        $this->assertStringStartsWith('صنف 00001001 - ', $copy->name);
        // العقدة الجديدة لها توأمها تحت الاب نفسه.
        $newTwin = Category::query()->where('code', $copy->sku)->firstOrFail();
        $this->assertSame((int) $parent->id, (int) $newTwin->parent_id);
        $this->assertSame((int) $copy->id, (int) $this->service->twinOf($newTwin)?->id);
        // الاصل كما هو.
        $this->assertSame(50.0, (float) $piece->fresh()->stock_quantity);
    }

    public function test_delete_follows_the_old_rules(): void
    {
        $category = $this->category('00001');
        [$twin, $main, $piece] = $this->twinMain('00002');
        $plain = $this->product('00001001', 'simple', null, $category);

        $this->from(route('products.tree'));

        // رئيسي له مقاطع ممنوع (حذف فئته التوأم حذف له).
        $this->delete(route('products.tree.categories.destroy', $twin->id))->assertSessionHas('error');
        $this->delete(route('products.tree.items.destroy', $main->id))->assertSessionHas('error');
        $this->assertNotNull($main->fresh());

        // فئة لها ابناء ممنوعة.
        $this->delete(route('products.tree.categories.destroy', $category->id))->assertSessionHas('error');
        $this->assertNotNull($category->fresh());

        // بلا حركات: حذف فعلي، ثم الرئيسي وتوأمه.
        $this->delete(route('products.tree.items.destroy', $piece->id))->assertSessionHas('success', __('Item deleted successfully'));
        $this->assertNull(Product::query()->find($piece->id));
        $this->delete(route('products.tree.categories.destroy', $twin->id))->assertSessionHas('success');
        $this->assertNull(Product::query()->find($main->id));
        $this->assertNull(Category::query()->find($twin->id));

        $this->delete(route('products.tree.items.destroy', $plain->id));
        $this->delete(route('products.tree.categories.destroy', $category->id))->assertSessionHas('success', __('Category deleted successfully'));
        $this->assertNull(Category::query()->find($category->id));
    }

    public function test_an_item_with_document_lines_is_deactivated_not_deleted(): void
    {
        $item = $this->documentedItem(barcode: 'OLD');
        ProductBarcode::query()->create(['product_id' => $item->id, 'barcode' => 'OLD2', 'pack_quantity' => 1, 'is_prefix' => false, 'sort_order' => 1]);

        $this->from(route('products.tree'))
            ->delete(route('products.tree.items.destroy', $item->id))
            ->assertSessionHas('success', __('The item has document lines, so it was deactivated.'));

        $item = $item->fresh();
        $this->assertNotNull($item);
        $this->assertSame(0, (int) $item->is_active);
        $this->assertNull($item->barcode);
        $this->assertSame(0, ProductBarcode::query()->where('product_id', $item->id)->count());
    }

    public function test_an_item_with_lines_and_a_balance_cannot_be_deleted(): void
    {
        $item = $this->documentedItem(barcode: 'OLD');
        DB::table($item->getTable())->where('id', $item->id)->update(['stock_quantity' => 3]);

        $this->from(route('products.tree'))
            ->delete(route('products.tree.items.destroy', $item->id))
            ->assertSessionHas('error');

        $this->assertSame(1, (int) $item->fresh()->is_active);
        $this->assertSame('OLD', $item->fresh()->barcode);
    }

    public function test_a_piece_used_only_inside_an_invoice_line_counts_as_a_document_line(): void
    {
        $this->assertContains('sales_invoice_item_pieces', $this->piecesTablesChecked());
    }

    public function test_adding_a_piece_fixes_type_and_parent_and_allows_a_repeated_name(): void
    {
        $other = $this->category('00009');
        [$twin, $main, $piece] = $this->twinMain('00002');

        $menu = $this->nodeMenu('category', $twin->id);
        $this->assertContains('add_piece', array_column($menu['actions'], 'key'));

        $this->getJson(route('products.tree.items.create', ['category' => $other->id, 'fixed_main' => $menu['product_id']]))
            ->assertOk()
            ->assertJsonPath('state.main_product_id', $main->id)
            ->assertJsonPath('state.pricing_type', 'weight');

        // النوع والاب ثابتان ولو وصل غيرهما.
        $page = $this->saveCard([
            'name' => $piece->name,
            'pricing_type' => 'simple',
            'main_product_id' => null,
            'fixed_main_id' => $main->id,
            'category_id' => $other->id,
        ])->assertSessionHasNoErrors();

        $created = Product::query()->where('main_product_id', $main->id)->whereKeyNot($piece->id)->firstOrFail();

        $this->assertSame('00002002', $created->sku);
        $this->assertSame('weight', $created->pricing_type);
        $this->assertSame([(int) $twin->id], $created->categories()->pluck((new Category)->getTable().'.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertNotSame($piece->slug, $created->slug);
        $this->assertNotNull($page);
    }

    public function test_a_repeated_piece_name_gets_a_unique_slug_even_against_a_deleted_item(): void
    {
        $first = $this->product('S1', 'simple', null, null, ['slug' => 'thabt']);
        $second = $this->product('S2', 'simple', null, null, ['slug' => 'thabt-1']);
        $second->delete();

        $this->assertSame('thabt-2', Product::uniqueSlug('thabt'));
        $this->assertSame('thabt', Product::uniqueSlug('thabt', $first->id));
        $this->assertSame('free-name', Product::uniqueSlug('free name'));
    }

    public function test_a_repeated_name_is_refused_for_anything_but_a_piece(): void
    {
        $category = $this->category('00001');
        $this->product('00001001', 'simple', null, $category, ['name' => 'مقبض']);

        $this->saveCard(['name' => 'مقبض', 'category_id' => $category->id])->assertSessionHasErrors('name');
    }

    public function test_code_is_automatic_and_read_only(): void
    {
        $category = $this->category('00001');

        $this->saveCard(['name' => 'مقبض جديد', 'sku' => 'HACK', 'category_id' => $category->id])->assertSessionHasNoErrors();

        $this->assertFalse(Product::query()->where('sku', 'HACK')->exists());
        $this->assertTrue(Product::query()->where('sku', '00001001')->exists());
    }

    public function test_adding_a_category_from_the_menu_goes_under_the_clicked_node(): void
    {
        $parent = $this->category('00001');

        $this->assertContains('add_category', array_column($this->nodeMenu('category', $parent->id)['actions'], 'key'));

        $this->from(route('products.tree'))
            ->post(route('products.tree.categories.store'), ['name' => 'فئة فرعية', 'parent_id' => $parent->id])
            ->assertSessionHasNoErrors();

        $this->assertSame((int) $parent->id, (int) Category::query()->where('code', '00001001')->value('parent_id'));
    }

    public function test_apply_to_other_colors_changes_only_measures_and_name_of_ticked_items(): void
    {
        [, , $source] = $this->twinMain('00001');
        [, $otherMain] = $this->twinMain('00002');
        [, $thirdMain] = $this->twinMain('00003');

        $ticked = $this->product('00002009', 'weight', $otherMain, null, ['barcode' => $source->barcode, 'weight_per_meter' => 1, 'length' => 1, 'base_price' => 77, 'sale_price' => 88]);
        DB::table($ticked->getTable())->where('id', $ticked->id)->update(['stock_quantity' => 5]);
        $unticked = $this->product('00003009', 'weight', $thirdMain, null, ['barcode' => $source->barcode, 'weight_per_meter' => 1, 'length' => 1]);
        $otherBarcode = $this->product('00003010', 'weight', $thirdMain, null, ['barcode' => 'DIFFERENT', 'weight_per_meter' => 1]);

        $card = $this->getJson(route('products.tree.items.edit', $source->id))->json();
        $this->assertFalse($card['state']['apply_to_colors']);
        $this->assertContains((int) $ticked->id, array_column($card['other_colors'], 'value'));

        $page = $this->saveCard([
            ...$card['state'],
            'name' => 'اسم جديد',
            'weight_per_meter' => 2.5,
            'length' => 5.8,
            'apply_to_colors' => true,
            'apply_to_color_ids' => [(string) $ticked->id],
        ], $source->id)->assertSessionHasNoErrors();

        $ticked->refresh();
        $this->assertSame(2.5, (float) $ticked->weight_per_meter);
        $this->assertSame(5.8, (float) $ticked->length);
        $this->assertSame('اسم جديد', $ticked->name);
        $this->assertSame(77.0, (float) $ticked->base_price);
        $this->assertSame(88.0, (float) $ticked->sale_price);
        $this->assertSame(5.0, (float) $ticked->stock_quantity);
        $this->assertSame(1.0, (float) $unticked->fresh()->weight_per_meter);
        $this->assertNotSame('اسم جديد', $unticked->fresh()->name);
        $this->assertNotContains((int) $otherBarcode->id, $this->service->sameProfileInOtherColors($source)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertNotNull($page);
    }

    public function test_saving_without_the_option_leaves_other_colors_alone(): void
    {
        [, , $source] = $this->twinMain('00001');
        [, $otherMain] = $this->twinMain('00002');
        $twin = $this->product('00002009', 'weight', $otherMain, null, ['barcode' => $source->barcode, 'weight_per_meter' => 1]);

        $this->saveCard([...$this->cardState($source->id), 'weight_per_meter' => 2.5], $source->id)->assertSessionHasNoErrors();

        $this->assertSame(1.0, (float) $twin->fresh()->weight_per_meter);
    }

    public function test_an_id_that_is_not_the_same_profile_is_ignored(): void
    {
        [, , $source] = $this->twinMain('00001');
        $stranger = $this->product('Z1', 'simple', null, null, ['weight_per_meter' => 1]);

        $this->assertSame(0, $this->service->applyToOtherColors($source, [$stranger->id]));
        $this->assertSame(1.0, (float) $stranger->fresh()->weight_per_meter);
    }

    private function documentedItem(string $barcode): Product
    {
        $item = $this->product('00001001', 'simple', null, $this->category('00001'), ['barcode' => $barcode]);
        $currency = \Modules\Accounting\tests\Unit\ConsolidatedTestFactory::currency();

        $invoice = \Modules\Accounting\Entities\SalesInvoice::create([
            'client_id' => User::factory()->create()->id,
            'reference_no' => 'SI-'.Str::random(10),
            'invoice_date' => now()->toDateString(),
            'currency_id' => $currency->id,
        ]);
        $invoice->items()->create(['product_id' => $item->id, 'description' => 'سطر', 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1]);

        return $item;
    }

    /**
     * @return array<int, string>
     */
    private function piecesTablesChecked(): array
    {
        preg_match_all("/'([a-z_]+_item_pieces)'/", file_get_contents(module_path('Items', 'Entities/Product.php')), $matches);

        return $matches[1];
    }

    public function test_saving_a_weight_main_from_the_card_keeps_its_saved_prices(): void
    {
        [, $main] = $this->twinMain('00002');
        $main->forceFill(['base_price' => 15, 'sale_price' => 17, 'wholesale_price' => 14, 'cost_price' => 9])->save();

        // البطاقة لا تكتب الا السعر الاساسي (ومخفي لصنف الوزن)، والتكلفة من المشتريات: ما وصل غيره لا يُحفظ.
        $this->saveCard([...$this->cardState($main->id), 'base_price' => 99, 'sale_price' => 99, 'wholesale_price' => 99, 'cost_price' => 10], $main->id)
            ->assertSessionHasNoErrors();

        $main->refresh();
        $this->assertEquals(15, $main->base_price);
        $this->assertEquals(17, $main->sale_price);
        $this->assertEquals(14, $main->wholesale_price);
        $this->assertEquals(9, $main->cost_price);
    }

    public function test_a_new_piece_is_saved_from_the_card_without_any_price(): void
    {
        [$twin, $main, $piece] = $this->twinMain('00002');

        // المقطع بلا سعر ولا تكلفة: الحقلان مخفيان فلا يُحفظان ولو وصلا.
        $this->saveCard(['name' => 'مقطع جديد', 'fixed_main_id' => $main->id, 'base_price' => 5, 'sale_price' => 6, 'cost_price' => 7])
            ->assertSessionHasNoErrors();

        $created = Product::query()->where('main_product_id', $main->id)->whereKeyNot($piece->id)->firstOrFail();
        $this->assertEquals(0, $created->base_price);
        $this->assertNull($created->sale_price);
        $this->assertNull($created->cost_price);
    }

    public function test_a_simple_item_card_still_requires_a_base_price(): void
    {
        $category = $this->category('00001');

        $this->saveCard(['name' => 'مقبض جديد', 'base_price' => null, 'category_id' => $category->id])->assertSessionHasErrors('base_price');
    }
}
