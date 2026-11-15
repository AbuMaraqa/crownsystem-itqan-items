<?php

namespace Modules\Items\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Support\StockLimits;
use Modules\Branch\Entities\Branch;
use Modules\Core\Repositories\BaseEloquentRepository;
use Modules\Items\Entities\Addon;
use Modules\Items\Entities\AddonOption;
use Modules\Items\Entities\Attribute;
use Modules\Items\Entities\AttributeValue;
use Modules\Items\Entities\Brand;
use Modules\Items\Entities\BranchPrice;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\ItemTypeProfile;
use Modules\Items\Entities\Label;
use Modules\Items\Entities\Manufacturer;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\Tag;
use Modules\Items\Entities\Unit;
use Modules\Items\Enums\ItemType;
use Modules\Items\Enums\ProfileThickness;
use Modules\Items\Enums\ServiceCostSource;
use Modules\Items\Enums\ServiceKind;
use Modules\Items\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Items\Support\ItemCardRules;
use Modules\Items\Support\ProductSearch;

class ProductRepository extends BaseEloquentRepository implements ProductRepositoryInterface
{
    private const LOOKUP_LIMIT = 25;

    public function __construct(Product $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()
            ->with([
                'translations',
                'media',
                'categories.translations',
                'subProducts' => fn ($query) => $query
                    ->select(['id', 'sku', 'barcode', 'main_product_id', 'length', 'weight_per_meter'])
                    ->with('translations:id,product_id,locale,name')
                    ->orderBy('id'),
            ])
            ->whereNull('parent_id')
            // الصنف الفرعي يظهر داخل صنفه الرئيسي لا صفاً مستقلاً.
            ->whereNull('main_product_id');
    }

    public function searchWithSubProducts(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $query) => ProductSearch::apply($query, $search)
            ->orWhereIn($query->qualifyColumn('id'), Product::query()
                ->select('main_product_id')
                ->whereNotNull('main_product_id')
                ->where(fn (Builder $query) => ProductSearch::apply($query, $search))));
    }

    public function inCategory(Builder $query, int $categoryId): Builder
    {
        $inCategory = fn (Builder $query) => $query->whereHas('categories', fn (Builder $categories) => $categories
            ->where((new Category)->getTable().'.id', $categoryId));

        // الصنف الرئيسي يظهر ايضاً اذا كان احد اصنافه الفرعية في التصنيف.
        return $query->where(fn (Builder $query) => $inCategory($query)
            ->orWhereIn($query->qualifyColumn('id'), Product::query()
                ->select('main_product_id')
                ->whereNotNull('main_product_id')
                ->where(fn (Builder $query) => $inCategory($query))));
    }

    public function findForEdit(int $id): Product
    {
        /** @var Product */
        return $this->query()->with([
            'translations', 'media', 'tags.translations', 'labels.translations', 'categories', 'manufacturers', 'branches',
            'addons', 'variations.translations', 'variations.categories', 'variations.attributeValues', 'variations.media',
        ])->findOrFail($id);
    }

    public function withVariations(int $id): Product
    {
        /** @var Product */
        return $this->query()->with(['translations', 'variations.translations'])->findOrFail($id);
    }

    public function formOptions(?Product $product = null): array
    {
        $named = fn ($models) => $models->map(fn ($model) => ['value' => (int) $model->id, 'label' => (string) $model->name])->values()->all();
        $pairs = fn (array $map) => collect($map)->map(fn ($label, $value) => ['value' => $value, 'label' => (string) $label])->values()->all();

        return [
            'categories' => $named(Category::query()->with('translations')->orderBy('id')->get()),
            'brands' => $named(Brand::query()->with('translations')->orderBy('id')->get()),
            'units' => $named(Unit::query()->with('translations')->orderBy('id')->get()),
            'manufacturers' => $named(Manufacturer::query()->with('translations')->orderBy('id')->get()),
            'branches' => $named(Branch::query()->with('translations')->orderBy('id')->get()),
            'tagSuggestions' => Tag::query()->with('translations')->get()->map(fn (Tag $tag) => (string) $tag->name)->filter()->unique()->values()->all(),
            'labelSuggestions' => Label::query()->with('translations')->get()->map(fn (Label $label) => (string) $label->name)->filter()->unique()->values()->all(),
            'itemTypeProfiles' => ItemTypeProfile::query()->with('translations')
                ->where(fn ($query) => $query->where('is_active', true)->when($product?->item_type_profile_id, fn ($query, $id) => $query->orWhere('id', $id)))
                ->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (ItemTypeProfile $profile) => ['value' => (int) $profile->id, 'label' => (string) $profile->name, 'aluminium' => $profile->isAluminium()])
                ->values()->all(),
            'itemTypes' => $pairs(ItemType::options()),
            'profileThicknesses' => $pairs(ProfileThickness::options()),
            'serviceKinds' => $pairs(ServiceKind::options()),
            'serviceCostSources' => $pairs(ServiceCostSource::options()),
            'revenueAccounts' => $pairs(ItemCardRules::ledgerAccountOptions(AccountType::REVENUE)),
            'costAccounts' => $pairs(ItemCardRules::ledgerAccountOptions(AccountType::EXPENSE)),
            'colors' => Product::colorOptions(),
            'attributes' => Attribute::query()->with(['translations', 'attributeValues.translations'])->orderBy('id')->get()
                ->map(fn (Attribute $attribute) => [
                    'id' => (int) $attribute->id,
                    'name' => (string) $attribute->name,
                    'values' => $attribute->attributeValues->map(fn (AttributeValue $value) => [
                        'id' => (int) $value->id,
                        'name' => (string) $value->name,
                        'color' => $value->color_code ?: null,
                    ])->values()->all(),
                ])->values()->all(),
            'addons' => Addon::query()->with(['translations', 'addonOptions.translations'])->orderBy('id')->get()
                ->map(fn (Addon $addon) => [
                    'id' => (int) $addon->id,
                    'name' => (string) $addon->name,
                    'options' => $addon->addonOptions->map(fn (AddonOption $option) => [
                        'id' => (int) $option->id,
                        'name' => (string) $option->name,
                        'price' => $option->price,
                    ])->values()->all(),
                ])->values()->all(),
            // حدود المخزون لا يعدّلها الا صاحب صلاحية «تعديل الحدود».
            'canEditLimits' => StockLimits::canEdit(),
        ];
    }

    public function lookup(string $type, string $search, ?int $exclude = null): array
    {
        $labels = match ($type) {
            // الصنف الرئيسي يحمل سعر الكيلوغرام، فلا تُعرض الا اصناف الوزن.
            'main' => ProductSearch::options($search, self::LOOKUP_LIMIT, 'weight'),
            // الملحق صنف مخزني يدخل جدول الاصناف في طلبية البيع؛ الخدمات لها قسمها.
            'goods' => ProductSearch::options($search, self::LOOKUP_LIMIT, itemType: ItemType::GOODS->value),
            default => ProductSearch::options($search, self::LOOKUP_LIMIT),
        };

        if ($exclude !== null) {
            unset($labels[$exclude]);
        }

        $lengths = Product::query()->whereKey(array_keys($labels))->pluck('length', 'id');

        return collect($labels)
            ->map(fn (string $label, int $id) => ['value' => $id, 'label' => $label, 'length' => $lengths->get($id)])
            ->values()
            ->all();
    }

    public function subProductRows(Product $product): array
    {
        return $product->subProducts()
            ->with('translations')
            ->orderBy('id')
            ->get(['id', 'sku', 'barcode', 'length', 'weight_per_meter'])
            ->map(fn (Product $sub) => [
                'id' => (int) $sub->id,
                'name' => (string) $sub->name,
                'sku' => (string) $sub->sku,
                'barcode' => (string) $sub->barcode,
                'length' => self::decimalLabel($sub->length),
                'weight_per_meter' => self::decimalLabel($sub->weight_per_meter),
            ])
            ->values()
            ->all();
    }

    public function madeFrom(Product $product): array
    {
        return $product->cuttingRecipesAsOutput()
            ->with(['sourceProduct.translations'])
            ->get()
            ->map(fn ($recipe) => [
                'label' => filled($recipe->sourceProduct) ? (string) ProductSearch::label($recipe->source_product_id) : '#'.$recipe->source_product_id,
                'yield' => rtrim(rtrim(number_format((float) $recipe->pieces_per_unit, 2), '0'), '.'),
            ])
            ->values()
            ->all();
    }

    public function branchPrices(iterable $productIds): Collection
    {
        return BranchPrice::query()
            ->whereIn('product_id', collect($productIds)->all())
            ->get()
            ->keyBy(fn (BranchPrice $price): string => "{$price->product_id}:{$price->branch_id}");
    }

    private static function decimalLabel(mixed $value): string
    {
        return filled($value) ? rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') : '—';
    }
}
