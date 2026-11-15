<?php

namespace Modules\Items\Services;

use DomainException;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductBarcode;
use Modules\Items\Enums\CategoryStatus;
use Modules\Items\Support\ItemCode;
use Modules\Items\Support\Search\ItemsSearchIndex;

/**
 * عمليات شجرة الاصناف كما في النظام القديم: النقل، ونسخ الصنف الرئيسي بمقاطعه، والحذف.
 * الصلاحيات في الصفحة التي تستدعيها، وكل عملية في معاملة واحدة.
 *
 * الصنف الرئيسي في الشجرة قد يكون له "توأم" فئة (رقمها رقمه)، فالاثنان عقدة واحدة.
 */
class ItemTreeService
{
    /**
     * صنف الوزن الذي ليس مقطعاً رئيسي، ولو بلا مقاطع بعد (a_al في النظام القديم).
     */
    public function isMain(Product $product): bool
    {
        return blank($product->main_product_id)
            && ($product->pricing_type === 'weight' || Product::query()->where('main_product_id', $product->id)->exists());
    }

    public function isPiece(Product $product): bool
    {
        return filled($product->main_product_id);
    }

    public function twinOf(Category $category): ?Product
    {
        if (blank($category->code)) {
            return null;
        }

        return Product::query()
            ->where('sku', $category->code)
            ->whereNull('parent_id')
            ->whereNull('main_product_id')
            ->whereHas('categories', fn ($query) => $query->where((new Category)->getTable().'.id', $category->id))
            ->first();
    }

    /**
     * الفئة التي هذا الصنف توأمها، ان وُجدت.
     */
    public function twinCategoryOf(Product $product): ?Category
    {
        return $product->categories()->where('code', $product->sku)->first();
    }

    /**
     * التوأم لعدة فئات باستعلام واحد: صنف مرتبط بالفئة رقمه رقمها وليس فرعياً لغيره.
     *
     * @param  iterable<Category>  $categories
     * @return array<int, int> معرّف التوأم مفهرساً بمعرّف الفئة
     */
    public function twinIds(iterable $categories): array
    {
        $codes = collect($categories)
            ->filter(fn (Category $category): bool => filled($category->code))
            ->mapWithKeys(fn (Category $category): array => [(int) $category->id => (string) $category->code]);

        if ($codes->isEmpty()) {
            return [];
        }

        $prefix = config('items.table_prefix');

        $rows = DB::table($prefix.'products as products')
            ->join($prefix.'products_categories as pivot', 'pivot.product_id', '=', 'products.id')
            ->whereIn('pivot.category_id', $codes->keys()->all())
            ->whereIn('products.sku', $codes->values()->unique()->all())
            ->whereNull('products.parent_id')
            ->whereNull('products.main_product_id')
            ->whereNull('products.deleted_at')
            ->get(['pivot.category_id', 'products.id', 'products.sku']);

        $twins = [];

        foreach ($rows as $row) {
            if (($codes[(int) $row->category_id] ?? null) === (string) $row->sku) {
                $twins[(int) $row->category_id] = (int) $row->id;
            }
        }

        return $twins;
    }

    public function twinIdOf(?int $categoryId): ?int
    {
        $category = $categoryId ? Category::query()->select(['id', 'code'])->find($categoryId) : null;

        return $category ? ($this->twinIds([$category])[$category->id] ?? null) : null;
    }

    /* ---------- قائمة الزر الايمن ---------- */

    /**
     * العقدة بنوعها: root، او category، او main (صنف رئيسي او فئة لها توأم فتُعامل معاملته)،
     * او piece، او item. null اذا لم توجد.
     *
     * @param  string  $kind  root او category او product
     * @return array{type: string, category: Category|null, product: Product|null}|null
     */
    public function node(string $kind, ?int $id): ?array
    {
        if ($kind === 'root') {
            return ['type' => 'root', 'category' => null, 'product' => null];
        }

        if ($kind === 'category') {
            $category = $id ? Category::query()->find($id) : null;

            if (! $category) {
                return null;
            }

            $twin = $this->twinOf($category);

            return ['type' => $twin ? 'main' : 'category', 'category' => $category, 'product' => $twin];
        }

        $product = $kind === 'product' && $id ? Product::query()->find($id) : null;

        if (! $product) {
            return null;
        }

        $type = match (true) {
            $this->isPiece($product) => 'piece',
            $this->isMain($product) => 'main',
            default => 'item',
        };

        return ['type' => $type, 'category' => null, 'product' => $product];
    }

    /**
     * اوامر القائمة بحسب نوع العقدة وصلاحيات المستخدم، كما في النظام القديم. الواجهة تعرضها،
     * وكل مسار عملية يفحص الصلاحية نفسها مرة اخرى.
     *
     * @param  array{type: string, category: Category|null, product: Product|null}  $node
     * @return list<array{key: string, label: string, confirm: string|null}>
     */
    public function nodeActions(array $node, ?Authorizable $user): array
    {
        $delete = ['delete', __('Delete'), __('Delete this item? An item with document lines is deactivated instead.')];
        $commands = match ($node['type']) {
            'root' => [['add_category', __('Add Category'), null], ['add_item', __('Add Item'), null]],
            'category' => [
                ['edit', __('Edit'), null],
                ['add_category', __('Add Category'), null],
                ['add_item', __('Add Item'), null],
                ...($this->categoryIsEmpty($node['category']) ? [['delete', __('Delete'), __('Delete this category?')]] : []),
                ['move', __('Move'), null],
                ['stock_report', __('Stock Balances'), null],
            ],
            'main' => [
                ['edit', __('Edit'), null],
                ['add_piece', __('Add Piece'), null],
                ['copy', __('Copy with Pieces'), __('Copy this main item with all its pieces?')],
                $delete,
                ['movement', __('Item Movement'), null],
            ],
            'piece' => [['edit', __('Edit'), null], $delete, ['movement', __('Item Movement'), null]],
            default => [['edit', __('Edit'), null], $delete, ['move', __('Move'), null], ['movement', __('Item Movement'), null]],
        };

        return collect($commands)
            ->filter(fn (array $command): bool => (bool) $user?->can($this->nodePermission($command[0], $node)))
            ->map(fn (array $command): array => ['key' => $command[0], 'label' => $command[1], 'confirm' => $command[2]])
            ->values()
            ->all();
    }

    /**
     * هل هذا الامر من اوامر العقدة لهذا المستخدم (النوع والصلاحية معاً).
     *
     * @param  array{type: string, category: Category|null, product: Product|null}  $node
     */
    public function allows(string $action, array $node, ?Authorizable $user): bool
    {
        return in_array($action, array_column($this->nodeActions($node, $user), 'key'), true);
    }

    /**
     * صلاحية امر القائمة على العقدة.
     *
     * @param  array{type: string, category: Category|null, product: Product|null}  $node
     */
    public function nodePermission(string $action, array $node): string
    {
        $isCategory = $node['type'] === 'category';

        return match ($action) {
            'add_category' => 'Category Create',
            'add_item', 'add_piece', 'copy' => 'Product Create',
            'edit', 'move' => $isCategory ? 'Category Update' : 'Product Update',
            'delete' => $isCategory ? 'Category Delete' : 'Product Delete',
            'movement' => 'Stock Item Card View',
            'stock_report' => 'Stock View List',
        };
    }

    public function categoryIsEmpty(Category $category): bool
    {
        return ! $category->allChildren()->exists() && ! $category->products()->exists();
    }

    /**
     * نقل صنف عادي الى فئة: رقم جديد تحتها. الصنف الرئيسي والمقطع لا يُنقلان.
     */
    public function moveProduct(Product $product, Category $target): Product
    {
        if ($this->isPiece($product) || $this->isMain($product)) {
            throw new DomainException(__('Main items and their pieces cannot be moved.'));
        }

        $this->assertTargetAcceptsChildren($target);

        return DB::transaction(function () use ($product, $target): Product {
            $product->sku = $this->nextCode($target->code);
            $product->save();
            $product->categories()->sync([$target->id]);

            app(ItemsSearchIndex::class)->refreshProducts([(int) $product->id]);

            return $product;
        });
    }

    /**
     * نقل فئة بما تحتها: رقم جديد لها ولكل ابنائها. $target = null: الى الجذر.
     */
    public function moveCategory(Category $category, ?Category $target): Category
    {
        if ($this->twinOf($category)) {
            throw new DomainException(__('Main items and their pieces cannot be moved.'));
        }

        if ($target) {
            $this->assertTargetAcceptsChildren($target);

            if ($this->isSameOrDescendant($target, $category)) {
                throw new DomainException(__('A category cannot be moved into itself or one of its subcategories.'));
            }
        }

        if ((int) $category->parent_id === (int) $target?->id) {
            throw new DomainException(__('The item is already in this category.'));
        }

        return DB::transaction(function () use ($category, $target): Category {
            $code = $target ? $this->nextCode($target->code) : ItemCode::nextRoot();

            $category->parent_id = $target?->id;
            $changed = [];
            $this->recodeCategory($category, $code, $changed);

            app(ItemsSearchIndex::class)->refreshProducts($changed);

            return $category;
        });
    }

    /**
     * صنف رئيسي جديد برقم جديد، وكل مقاطعه بارقام جديدة وبالباركودات نفسها (قد تتكرر)،
     * بلا رصيد ولا مستندات. الصنف الذي له توأم فئة تُنشأ له فئة توأم جديدة.
     */
    public function copyMain(Product $main): Product
    {
        if (! $this->isMain($main)) {
            throw new DomainException(__('Only a main item can be copied with its pieces.'));
        }

        return DB::transaction(function () use ($main): Product {
            $twin = $this->twinCategoryOf($main);
            $categoryIds = $main->categories()->pluck((new Category)->getTable().'.id')->all();

            if ($twin) {
                $newTwin = new Category([
                    'slug' => 'cat-'.Str::lower(Str::random(10)),
                    'status' => $twin->status ?? CategoryStatus::ACTIVE,
                    'parent_id' => $twin->parent_id,
                    'sort_order' => $twin->sort_order,
                    'created_by' => auth()->id(),
                ]);
                $newTwin->save();

                foreach ($twin->translations as $translation) {
                    $newTwin->translateOrNew($translation->locale)->name = $translation->name.' - '.__('Copy');
                }

                $newTwin->save();

                $sku = $newTwin->code;
                $categoryIds = [$newTwin->id];
            } else {
                $parentCode = $main->categories()->value('code');
                $sku = filled($parentCode) ? $this->nextCode($parentCode) : null;
            }

            $copy = $this->replicate($main, $sku, null, $categoryIds, ' - '.__('Copy'));

            Product::query()
                ->with(['translations', 'productBarcodes'])
                ->where('main_product_id', $main->id)
                ->orderBy('sku')
                ->get()
                ->each(fn (Product $piece) => $this->replicate($piece, $this->nextCode($copy->sku), $copy->id, $categoryIds));

            return $copy;
        });
    }

    /**
     * حذف صنف كما في النظام القديم: رئيسي له مقاطع ممنوع، وله حركات ورصيد ممنوع، وله
     * حركات بلا رصيد يُعطَّل ويُفرَّغ باركوده، وبلا حركات يُحذف.
     *
     * @return string deleted او deactivated
     */
    public function removeProduct(Product $product): string
    {
        if (Product::query()->where('main_product_id', $product->id)->exists()) {
            throw new DomainException(__('Remove the sub-items of this main item first.'));
        }

        if ($product->hasDocumentLines()) {
            if (abs((float) $product->stock_quantity) > 0 || abs((float) $product->stock_weight) > 0) {
                throw new DomainException(__('This item has movements and a stock balance, so it cannot be deleted.'));
            }

            DB::transaction(function () use ($product): void {
                $product->update(['is_active' => 0, 'barcode' => null]);
                ProductBarcode::query()->where('product_id', $product->id)->delete();
            });

            return 'deactivated';
        }

        DB::transaction(function () use ($product): void {
            $twin = $this->twinCategoryOf($product);

            // المقطع المحذوف لا يبقى مانعاً لحذف رئيسيه (deletionBlocker يعدّ المحذوف).
            $product->update(['main_product_id' => null]);
            $product->delete();

            if ($twin && ! $twin->allChildren()->exists() && ! $twin->products()->exists()) {
                $twin->delete();
            }
        });

        return 'deleted';
    }

    /**
     * بطاقة الفئة في الشجرة: فئة جديدة تحت ابيها (رقمها من رقمه)، او تعديل الاسم فقط.
     */
    public function saveCategory(?Category $category, string $name, ?int $parentId = null): Category
    {
        return DB::transaction(function () use ($category, $name, $parentId): Category {
            $category ??= new Category([
                'slug' => 'cat-'.Str::lower(Str::random(10)),
                'status' => CategoryStatus::ACTIVE,
                'parent_id' => $parentId,
                'created_by' => auth()->id(),
            ]);

            $category->save();
            $category->translateOrNew(app()->getLocale())->name = $name;
            $category->save();

            return $category;
        });
    }

    public function removeCategory(Category $category): void
    {
        if ($category->allChildren()->exists()) {
            throw new DomainException(__('Remove the subcategories first.'));
        }

        if ($category->products()->exists()) {
            throw new DomainException(__('This category still has products linked to it.'));
        }

        $category->delete();
    }

    /**
     * نفس المقطع تحت اصناف رئيسية اخرى: ما يشاركه باركوداً، رئيسياً كان او اضافياً.
     *
     * @return Collection<int, Product>
     */
    public function sameProfileInOtherColors(Product $piece): Collection
    {
        if (! $this->isPiece($piece)) {
            return collect();
        }

        $codes = ProductBarcode::query()
            ->where('product_id', $piece->id)
            ->where('is_prefix', false)
            ->pluck('barcode')
            ->push($piece->barcode)
            ->filter(fn ($code): bool => filled($code))
            ->unique()
            ->values()
            ->all();

        if ($codes === []) {
            return collect();
        }

        return Product::query()
            ->with(['translations', 'mainProduct.translations'])
            ->whereNotNull('main_product_id')
            ->where('main_product_id', '!=', $piece->main_product_id)
            ->whereKeyNot($piece->id)
            ->where(fn ($query) => $query
                ->whereIn('barcode', $codes)
                ->orWhereHas('productBarcodes', fn ($query) => $query->whereIn('barcode', $codes)->where('is_prefix', false)))
            ->orderBy('sku')
            ->get();
    }

    /**
     * ينقل وزن المتر والطول والاسم من المقطع الى ما اختاره المستخدم من الوانه. لا يمس
     * سعراً ولا رصيداً، وما ليس من نفس المقطع يُتجاهل.
     *
     * @param  array<int, mixed>  $ids
     */
    public function applyToOtherColors(Product $piece, array $ids): int
    {
        $ids = array_map('intval', $ids);
        $targets = $this->sameProfileInOtherColors($piece)->filter(fn (Product $target): bool => in_array((int) $target->id, $ids, true));
        $locale = app()->getLocale();

        return DB::transaction(function () use ($piece, $targets, $locale): int {
            foreach ($targets as $target) {
                $target->weight_per_meter = $piece->weight_per_meter;
                $target->length = $piece->length;
                $target->translateOrNew($locale)->name = $piece->translate($locale)?->name ?? $piece->name;
                $target->save();
            }

            return $targets->count();
        });
    }

    /**
     * @param  array<int, int>  $changed  الاصناف التي تغيّر رقمها، لتحديث فهرس البحث
     */
    private function recodeCategory(Category $category, string $code, array &$changed): void
    {
        $twin = $this->twinOf($category);

        $category->code = $code;
        $category->save();

        if ($twin) {
            $twin->sku = $code;
            $twin->save();
            $changed[] = (int) $twin->id;
            $this->recodePieces($twin, $changed);
        }

        Category::query()
            ->where('parent_id', $category->id)
            ->orderBy('code')
            ->get()
            ->each(fn (Category $child) => $this->recodeCategory($child, $this->nextCode($code), $changed));

        $this->directProducts($category, $twin)->each(function (Product $product) use ($code, &$changed): void {
            $product->sku = $this->nextCode($code);
            $product->save();
            $changed[] = (int) $product->id;
            $this->recodePieces($product, $changed);
        });
    }

    /**
     * @param  array<int, int>  $changed
     */
    private function recodePieces(Product $main, array &$changed): void
    {
        Product::query()
            ->where('main_product_id', $main->id)
            ->orderBy('sku')
            ->get()
            ->each(function (Product $piece) use ($main, &$changed): void {
                $piece->sku = $this->nextCode($main->sku);
                $piece->save();
                $changed[] = (int) $piece->id;
            });
    }

    /**
     * @return Collection<int, Product>
     */
    private function directProducts(Category $category, ?Product $twin): Collection
    {
        return Product::query()
            ->whereNull('parent_id')
            ->whereNull('main_product_id')
            ->when($twin, fn ($query) => $query->whereKeyNot($twin->id))
            ->whereHas('categories', fn ($query) => $query->where((new Category)->getTable().'.id', $category->id))
            ->orderBy('sku')
            ->get();
    }

    /**
     * @param  array<int, int|string>  $categoryIds
     */
    private function replicate(Product $source, ?string $sku, ?int $mainId, array $categoryIds, string $nameSuffix = ''): Product
    {
        $copy = $source->replicate(['slug', 'sku', 'main_product_id', 'stock_quantity', 'stock_weight', 'meta_data', 'created_by']);
        $copy->forceFill([
            'slug' => 'item-'.Str::lower(Str::random(12)),
            'sku' => $sku,
            'main_product_id' => $mainId,
            'stock_quantity' => 0,
            'stock_weight' => 0,
            'meta_data' => null,
            'created_by' => auth()->id(),
        ]);
        // replicate ينقل العلاقات المحمّلة، فبدون هذا تُكتب الترجمات على صفوف الاصل.
        $copy->unsetRelations();
        $copy->save();

        foreach ($source->translations as $translation) {
            $copy->translateOrNew($translation->locale)->fill([
                'name' => $translation->name.$nameSuffix,
                'description' => $translation->description,
                'short_description' => $translation->short_description,
            ]);
        }

        $copy->save();
        $copy->categories()->sync($categoryIds);

        foreach ($source->productBarcodes as $barcode) {
            ProductBarcode::query()->create([
                'product_id' => $copy->id,
                // وحدات الصنف لا تُنسخ، فالباركود على الوحدة الاساسية.
                'product_unit_id' => null,
                'barcode' => $barcode->barcode,
                'pack_quantity' => $barcode->pack_quantity,
                'is_prefix' => $barcode->is_prefix,
                'sort_order' => $barcode->sort_order,
                'created_by' => auth()->id(),
            ]);
        }

        return $copy;
    }

    private function assertTargetAcceptsChildren(Category $target): void
    {
        if ($this->twinOf($target)) {
            throw new DomainException(__('The destination must be a category, not a main item.'));
        }

        if (blank($target->code)) {
            throw new DomainException(__('The destination category has no number.'));
        }
    }

    private function isSameOrDescendant(Category $candidate, Category $ancestor): bool
    {
        $seen = [];

        for ($cursor = $candidate; $cursor && ! isset($seen[$cursor->id]); $cursor = $cursor->parent_id ? Category::query()->find($cursor->parent_id) : null) {
            if ((int) $cursor->id === (int) $ancestor->id) {
                return true;
            }

            $seen[$cursor->id] = true;
        }

        return false;
    }

    private function nextCode(string $parentCode): string
    {
        return ItemCode::nextChild($parentCode)
            ?? throw new DomainException(__('No more items can be added to this category because it is full.'));
    }
}
