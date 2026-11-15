<?php

namespace Modules\Items\Services;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Support\StockLimits;
use Modules\Branch\Entities\Branch;
use Modules\Items\Entities\AttributeValue;
use Modules\Items\Entities\BranchPrice;
use Modules\Items\Entities\Label;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\Tag;
use Modules\Items\Enums\ItemType;
use Modules\Items\Enums\ProductActive;
use Modules\Items\Events\ProductUpdated;
use Modules\Items\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Items\Support\ItemCardRules;

/**
 * كتابة بطاقة الصنف من صفحات Vue (اضافة وتعديل وقائمة الاصناف واسعار الفروع). كل حفظ في معاملة
 * واحدة، والاسم والوصف يُكتبان بلغة الواجهة الحالية فقط.
 *
 * ما تخفيه البطاقة لا يُكتب، كما في صفحة Livewire السابقة:
 * - صنف الوزن: لا وسوم ولا شارات ولا علامة ولا مصنّعين ولا اضافات ولا متغيرات ولا معلومات وصفية،
 *   ولا سعر اساسي او بيع او جملة ولا خصم (سعر الكيلو من قوائم الاسعار). المقطع (صنف وزن تحت صنف
 *   رئيسي) بلا سعر تكلفة ايضاً. في الاضافة تُصفَّر هذه الاسعار، وفي التعديل تبقى القيم المحفوظة.
 * - الخدمة: لا لون ولا حدود مخزون.
 * - حدود المخزون لا يكتبها الا صاحب صلاحية «تعديل الحدود».
 */
class ProductService
{
    /** حقول البطاقة التي هي اعمدة في جدول الاصناف (طبيعة الصنف ونوعه من ItemCardRules). */
    private const COLUMNS = [
        'slug', 'sku', 'barcode', 'base_price', 'sale_price', 'cost_price', 'wholesale_price',
        'discount_type', 'discount_value', 'discount_start_date', 'discount_end_date',
        'weight_per_meter', 'length', 'pricing_type', 'brand_id', 'unit_id', 'site_area',
        'color', 'max_limit', 'min_limit_1', 'min_limit_2',
    ];

    private const TRANSLATED = ['name', 'short_description', 'description'];

    /** حقول التجارة الالكترونية التي تختفي عن صنف الوزن. */
    private const COMMERCE = [
        'tags', 'labels', 'brand_id', 'manufacturers', 'addons', 'variations',
        'base_price', 'sale_price', 'wholesale_price',
        'discount_type', 'discount_value', 'discount_start_date', 'discount_end_date',
        'meta_title', 'meta_description', 'meta_keywords',
    ];

    private const VARIATION_COLUMNS = [
        'sku', 'barcode', 'base_price', 'sale_price', 'cost_price', 'wholesale_price',
        'discount_type', 'discount_value', 'discount_start_date', 'discount_end_date',
        'max_limit', 'min_limit_1', 'min_limit_2',
    ];

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductAccessoryService $accessories,
    ) {}

    /**
     * @param  array<string, mixed>  $data  حالة النموذج كما تحققت منها ProductRequest
     */
    public function create(array $data, string $locale, int $userId): Product
    {
        $data = $this->visible($data);

        // صنف الوزن بلا سعر في بطاقته: سعر الكيلو من قوائم الاسعار، والمقطع بلا سعر ولا تكلفة.
        if (($data['pricing_type'] ?? 'simple') === 'weight') {
            $data['base_price'] = 0;
            $data['sale_price'] = null;
            $data['wholesale_price'] = null;

            if ($this->isPiece($data)) {
                $data['cost_price'] = null;
            }
        }

        $product = DB::transaction(function () use ($data, $locale, $userId): Product {
            /** @var Product $product */
            $product = $this->products->query()->newModelInstance();
            $product->fill([
                // اعمدة لا تقبل null في القاعدة: قيمتها الافتراضية عند خلو النموذج منها.
                'is_featured' => 0,
                'is_digital' => 0,
                'allow_backorder' => 0,
                'stock_status' => 'in_stock',
                'status' => 1,
                'low_stock_threshold' => 10,
                ...$this->columns($data, null),
                'base_price' => $data['base_price'] ?? 0,
                'pricing_type' => $data['pricing_type'] ?? 'simple',
                'created_by' => $userId,
            ]);
            $this->fillTranslations($product, $data, $locale);
            $product->save();

            $this->saveRelations($product, $data, $locale);
            $this->saveMedia($product, $data);

            if (! empty($data['variations'])) {
                $this->syncVariations($product, $data['variations'], $locale, $userId);
            }

            return $product;
        });

        event(new ProductUpdated);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data, string $locale, int $userId): Product
    {
        $data = $this->visible($data);

        return DB::transaction(function () use ($product, $data, $locale, $userId): Product {
            $product->fill($this->columns($data, $product));
            $this->fillTranslations($product, $data, $locale);
            $product->save();

            $this->saveRelations($product, $data, $locale);
            $this->saveMedia($product, $data);

            // قائمة متغيرات فارغة لا تحذف المحفوظ، كما في الصفحة السابقة.
            if (! empty($data['variations'])) {
                $this->syncVariations($product, $data['variations'], $locale, $userId);
            }

            return $product;
        });
    }

    public function setActive(Product $product, bool $active): void
    {
        $product->update([
            'is_active' => $active ? ProductActive::IS_ACTIVE->value : ProductActive::IN_ACTIVE->value,
        ]);
    }

    /**
     * حذف نهائي، الا الصنف الرئيسي الذي تحته فروع والصنف المستخدم في مستند (deletionBlocker).
     *
     * @throws DomainException بسبب المنع
     */
    public function delete(Product $product): void
    {
        if ($reason = $product->deletionBlocker()) {
            throw new DomainException($reason);
        }

        $product->forceDelete();
    }

    /**
     * حذف التحديد من القائمة: ما يمنعه deletionBlocker يبقى.
     *
     * @param  list<int>  $ids
     * @return int عدد الاصناف التي لم تُحذف
     */
    public function deleteMany(array $ids): int
    {
        return DB::transaction(function () use ($ids): int {
            [$blocked, $deletable] = $this->products->query()->whereKey($ids)->get()
                ->partition(fn (Product $product): bool => $product->deletionBlocker() !== null);

            $deletable->each->delete();

            return $blocked->count();
        });
    }

    /**
     * اسعار الصنف ومتغيراته في الفروع. السعر الفارغ يحذف سعر الفرع.
     *
     * @param  array<int|string, array<int|string, mixed>>  $prices  صنف او متغير => [فرع => سعر]
     */
    public function saveBranchPrices(Product $product, array $prices): void
    {
        // هذا الصنف ومتغيراته وفروع موجودة فقط: المصفوفة من المتصفح.
        $productIds = $product->variations()->pluck('id')->push($product->id)->map(fn ($id) => (int) $id)->all();
        $branchIds = Branch::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($prices, $productIds, $branchIds): void {
            foreach ($prices as $productId => $branchPrices) {
                if (! in_array((int) $productId, $productIds, true) || ! is_array($branchPrices)) {
                    continue;
                }

                foreach ($branchPrices as $branchId => $price) {
                    if (! in_array((int) $branchId, $branchIds, true)) {
                        continue;
                    }

                    $match = ['product_id' => (int) $productId, 'branch_id' => (int) $branchId];

                    if ($price === null || $price === '') {
                        BranchPrice::query()->where($match)->delete();
                    } else {
                        BranchPrice::query()->updateOrCreate($match, ['price' => $price]);
                    }
                }
            }
        });
    }

    /**
     * ما تعرضه البطاقة فقط (انظر وصف الصنف اعلاه).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function visible(array $data): array
    {
        $isService = ($data['item_type'] ?? null) === ItemType::SERVICE->value;

        if (($data['pricing_type'] ?? null) === 'weight') {
            $data = Arr::except($data, self::COMMERCE);

            if ($this->isPiece($data)) {
                unset($data['cost_price']);
            }
        }

        if ($isService) {
            $data = Arr::except($data, ['color', ...StockLimits::FIELDS]);
        }

        if (! StockLimits::canEdit()) {
            $data = Arr::except($data, StockLimits::FIELDS);

            if (isset($data['variations']) && is_array($data['variations'])) {
                $data['variations'] = array_map(fn ($row) => is_array($row) ? Arr::except($row, StockLimits::FIELDS) : $row, $data['variations']);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isPiece(array $data): bool
    {
        return ($data['pricing_type'] ?? null) === 'weight'
            && ($data['item_type'] ?? null) !== ItemType::SERVICE->value
            && filled($data['main_product_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data, ?Product $product): array
    {
        $columns = Arr::only($data, self::COLUMNS);

        // رابط فارغ يُصنع من الاسم، ويُضاف رقم اذا تكرر (اسم المقطع يتكرر بين الالوان).
        if (blank($columns['slug'] ?? null)) {
            $columns['slug'] = Product::uniqueSlug($data['name'] ?? null, $product?->id);
        }

        // رقم الصنف الفارغ: يُولَّد عند الاضافة، ويبقى الرقم الحالي عند التعديل.
        if (blank($columns['sku'] ?? null)) {
            unset($columns['sku']);
        }

        if (array_key_exists('discount_type', $columns) && blank($columns['discount_type'])) {
            $columns['discount_type'] = 'none';
        }

        return [...$columns, ...ItemCardRules::itemTypeAttributes($data), ...ItemCardRules::itemModelAttributes($data)];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillTranslations(Product $product, array $data, string $locale): void
    {
        $values = Arr::only($data, self::TRANSLATED);

        if ($values !== []) {
            $product->translateOrNew($locale)->fill($values);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveRelations(Product $product, array $data, string $locale): void
    {
        if (array_key_exists('tags', $data)) {
            $product->tags()->sync($this->namedIds(Tag::class, (array) $data['tags'], $locale));
        }

        if (array_key_exists('labels', $data)) {
            $product->labels()->sync($this->namedIds(Label::class, (array) $data['labels'], $locale));
        }

        foreach (['category_id' => 'categories', 'manufacturers' => 'manufacturers', 'branches' => 'branches'] as $key => $relation) {
            if (array_key_exists($key, $data)) {
                $product->{$relation}()->sync(array_map('intval', (array) $data[$key]));
            }
        }

        if (array_key_exists('addons', $data)) {
            $this->syncAddons($product, (array) $data['addons']);
        }

        $this->accessories->sync($product, (array) ($data['accessories'] ?? []));
        $this->syncCuttingRecipes($product, (array) ($data['cutting_recipes'] ?? []));
    }

    /**
     * معرّفات الوسوم او الشارات بأسمائها؛ الاسم الجديد يُنشأ بلغة الواجهة.
     *
     * @param  class-string<Tag|Label>  $model
     * @param  array<int, mixed>  $names
     * @return list<int>
     */
    private function namedIds(string $model, array $names, string $locale): array
    {
        return collect($names)->filter(fn ($name) => filled($name))->unique()->map(function ($name) use ($model, $locale): int {
            $record = $model::whereTranslation('name', $name)->first();

            if (! $record) {
                $record = new $model;
                $record->translateOrNew($locale)->name = $name;
                $record->save();
            }

            return (int) $record->id;
        })->values()->all();
    }

    /**
     * يستبدل اضافات الصنف وخياراتها المسموحة باسعارها.
     *
     * @param  array<int, mixed>  $addons
     */
    private function syncAddons(Product $product, array $addons): void
    {
        $prefix = config('items.table_prefix');
        $old = DB::table($prefix.'addon_product')->where('product_id', $product->id)->pluck('id');

        if ($old->isNotEmpty()) {
            DB::table($prefix.'addon_product_option')->whereIn('addon_product_id', $old)->delete();
            DB::table($prefix.'addon_product')->where('product_id', $product->id)->delete();
        }

        foreach ($addons as $addon) {
            if (! is_array($addon) || blank($addon['addon_id'] ?? null)) {
                continue;
            }

            $addonProductId = DB::table($prefix.'addon_product')->insertGetId([
                'addon_id' => (int) $addon['addon_id'],
                'product_id' => $product->id,
                'is_required' => (bool) ($addon['is_required'] ?? false),
                'min_selection' => $addon['min_selection'] ?? 1,
                'max_selection' => $addon['max_selection'] ?? null,
                'max_quantity_per_option' => $addon['max_quantity_per_option'] ?? 1,
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $options = collect((array) ($addon['allowed_options'] ?? []))
                ->filter(fn ($option) => is_array($option) && filled($option['addon_option_id'] ?? null))
                ->map(fn (array $option) => [
                    'addon_product_id' => $addonProductId,
                    'addon_option_id' => (int) $option['addon_option_id'],
                    'price' => $option['price'] ?? null,
                    'is_default' => (int) (bool) ($option['is_default'] ?? false),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all();

            if ($options !== []) {
                DB::table($prefix.'addon_product_option')->insert($options);
            }
        }
    }

    /**
     * وصفات القص: الاصناف التي تُقص من هذا الصنف وعدد القطع من كل وحدة.
     *
     * @param  array<int, mixed>  $rows
     */
    private function syncCuttingRecipes(Product $product, array $rows): void
    {
        $recipes = collect($rows)->filter(fn ($row) => is_array($row) && filled($row['output_product_id'] ?? null));
        $keep = $recipes->pluck('id')->filter()->map(fn ($id) => (int) $id)->values();

        $product->cuttingRecipesAsSource()
            ->when($keep->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $keep))
            ->delete();

        foreach ($recipes as $recipe) {
            $values = [
                'output_product_id' => (int) $recipe['output_product_id'],
                'pieces_per_unit' => $recipe['pieces_per_unit'] ?? 0,
            ];

            $existing = filled($recipe['id'] ?? null) ? $product->cuttingRecipesAsSource()->whereKey($recipe['id'])->first() : null;

            $existing ? $existing->update($values) : $product->cuttingRecipesAsSource()->create($values);
        }
    }

    /**
     * الصورة والمعرض: ما حذفه المستخدم من المحفوظ، ثم الملفات الجديدة. المعرّفات من المتصفح لا
     * تصل الا الى صور هذا الصنف.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveMedia(Product $product, array $data): void
    {
        foreach (['product' => 'removed_image', 'product_gallery' => 'removed_gallery'] as $collection => $key) {
            if (! empty($data[$key])) {
                $product->getMedia($collection)->whereIn('id', array_map('intval', (array) $data[$key]))->each->delete();
                $product->unsetRelation('media');
            }
        }

        $uploaded = fn ($files): array => array_values(array_filter(is_array($files) ? $files : [$files], fn ($file) => $file instanceof UploadedFile));

        if ($image = $uploaded($data['image'] ?? null)[0] ?? null) {
            $product->addImage($image);
        }

        if ($gallery = $uploaded($data['gallery'] ?? null)) {
            $product->addGalleryImage($gallery);
        }
    }

    /**
     * متغيرات الصنف كما في النموذج: الموجود يُحدَّث، والجديد يُنشأ (او يُستعاد المحذوف بنفس
     * الرابط)، وما لم يعد في القائمة يُحذف.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncVariations(Product $product, array $rows, string $locale, int $userId): void
    {
        $existing = $product->variations()->get()->keyBy('id');
        $kept = [];

        foreach ($rows as $row) {
            $id = filled($row['id'] ?? null) ? (int) $row['id'] : null;
            $valueIds = collect((array) ($row['attribute_values'] ?? []))->filter(fn ($value) => filled($value))->map(fn ($value) => (int) $value)->unique()->values()->all();
            $name = filled($row['name'] ?? null) ? (string) $row['name'] : $this->variationName((string) $product->name, $valueIds);

            $fields = Arr::only($row, self::VARIATION_COLUMNS);
            $fields['parent_id'] = $product->id;
            $fields['base_price'] = $fields['base_price'] ?? 0;
            $fields['discount_type'] = blank($fields['discount_type'] ?? null) ? 'none' : $fields['discount_type'];

            if (blank($fields['sku'] ?? null)) {
                unset($fields['sku']);
            }

            $slug = Str::slug(filled($row['slug'] ?? null) ? (string) $row['slug'] : $name);

            if ($id && $existing->has($id)) {
                $variation = $existing->get($id);
                $fields['slug'] = $this->uniqueSlug($slug, $id);
            } elseif ($trashed = Product::onlyTrashed()->where('slug', $slug)->where('parent_id', $product->id)->first()) {
                $trashed->restore();
                $variation = $trashed;
                $fields['slug'] = $slug;
            } else {
                $variation = $this->products->query()->newModelInstance();
                $fields = [
                    'manage_stock' => 1,
                    'stock_status' => 'in_stock',
                    'is_featured' => 0,
                    'is_digital' => 0,
                    ...$fields,
                    'slug' => $this->uniqueSlug($slug),
                    'created_by' => $userId,
                ];
            }

            $variation->fill($fields);
            $variation->translateOrNew($locale)->fill([
                'name' => $name,
                'short_description' => $row['short_description'] ?? null,
                'description' => $row['description'] ?? null,
            ]);
            $variation->save();
            $kept[] = (int) $variation->id;

            if ($valueIds !== []) {
                $variation->attributeValues()->sync($valueIds);
            }

            if (! empty($row['category_id'])) {
                $variation->categories()->sync(array_map('intval', (array) $row['category_id']));
            }

            $this->saveMedia($variation, $row);
        }

        $removed = $existing->keys()->diff($kept);

        if ($removed->isNotEmpty()) {
            Product::query()->whereIn('id', $removed)->delete();
        }
    }

    /**
     * @param  list<int>  $valueIds
     */
    private function variationName(string $parentName, array $valueIds): string
    {
        $values = AttributeValue::query()->with('translations')->whereIn('id', $valueIds)->get()->pluck('name')->all();

        return trim(implode(' - ', array_filter([$parentName, ...$values])));
    }

    /**
     * رابط لا يتكرر، والمحذوف يُعدّ لان فهرس الرابط يشمله.
     */
    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $base = $slug !== '' ? $slug : 'item';
        $candidate = $base;
        $counter = 1;

        while (Product::withTrashed()->where('slug', $candidate)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $candidate = $base.'-'.$counter++;
        }

        return $candidate;
    }
}
