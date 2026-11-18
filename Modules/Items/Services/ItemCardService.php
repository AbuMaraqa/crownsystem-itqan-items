<?php

namespace Modules\Items\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Accounting\Support\StockLimits;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ItemType;
use Modules\Items\Repositories\Contracts\ItemTreeRepositoryInterface;
use Modules\Items\Support\ItemCard;
use Modules\Items\Support\ItemCardRules;
use Modules\Items\Support\ItemCode;
use Modules\Items\Support\ProductSearch;

/**
 * بطاقة الصنف في شجرة الاصناف: ما تُفتح عليه، وحفظها.
 *
 * رقم الصنف تلقائي وللقراءة فقط كما في النظام القديم: للصنف الجديد يُولَّد من رقم صنفه
 * الرئيسي او رقم فئته. الحقل المخفي في البطاقة لا يصل، فتبقى قيمته المحفوظة (اسعار صنف
 * الوزن، تكلفة المقطع، حقول الالمنيوم لصنف الكمية، الحدود لمن لا يملك تعديلها).
 */
class ItemCardService
{
    public function __construct(
        private readonly ItemTreeRepositoryInterface $tree,
        private readonly ItemTreeService $treeService,
    ) {}

    /**
     * البطاقة لصنف محفوظ.
     *
     * @return array<string, mixed>
     */
    public function edit(Product $product): array
    {
        $product->loadMissing(['translations', 'media']);
        $media = $product->getFirstMedia('product');
        $state = [
            'barcode' => $product->barcode,
            'name' => $product->name,
            'sku' => $product->sku,
            'pricing_type' => $product->pricing_type ?: 'simple',
            'item_type' => $product->item_type ?: ItemType::GOODS->value,
            'service_kind' => $product->service_kind,
            'service_cost_source' => $product->service_cost_source,
            'revenue_account_id' => $product->revenue_account_id,
            'cost_account_id' => $product->cost_account_id,
            'main_product_id' => $product->main_product_id,
            'base_price' => $product->base_price,
            'length' => $product->length,
            'weight_per_meter' => $product->weight_per_meter,
            'synonym_product_id' => $product->synonym_product_id,
            'shelf' => $product->shelf,
            'color' => $product->color,
            'site_area' => $product->site_area,
            'min_limit_1' => $product->min_limit_1,
            'min_limit_2' => $product->min_limit_2,
            'max_limit' => $product->max_limit,
            'unit_id' => $product->unit_id,
            ...ItemCardRules::itemModelFormState($product),
            'short_description' => $product->short_description,
            'supplier_ids' => $product->suppliers()->pluck('users.id')->map(fn ($id): int => (int) $id)->all(),
            // تطبيق المقطع على الالوان الاخرى اختيار لكل حفظ، ومعطَّل افتراضياً.
            'apply_to_colors' => false,
            'apply_to_color_ids' => [],
        ];

        return [
            'id' => (int) $product->id,
            'state' => $state,
            'image' => $media ? ['id' => $media->id, 'url' => $media->getUrl()] : null,
            // التكلفة لا تُكتب في البطاقة: هي المتوسط المرجّح من المشتريات الفعلية، للعرض فقط.
            'average_cost' => app(StockLedgerService::class)->averageCost((int) $product->id),
            'other_colors' => $this->treeService->sameProfileInOtherColors($product)
                ->map(fn (Product $target): array => ['value' => (int) $target->id, 'label' => trim($target->sku.' - '.$target->mainProduct?->name)])
                ->values()
                ->all(),
            ...$this->context($product, null, $state),
        ];
    }

    /**
     * بطاقة صنف جديد. يُقترح تابعاً للصنف الرئيسي المختار، وفي التصنيف المختار؛ واضافة مقطع
     * من الشجرة ($fixedMainId) تثبّت النوع والاب.
     *
     * @return array<string, mixed>
     */
    public function create(?int $selectedCategoryId, ?int $selectedProductId, ?int $highlightedId, ?int $fixedMainId): array
    {
        $mainId = $fixedMainId ?: $this->tree->suggestedMainId($highlightedId, $selectedProductId, $selectedCategoryId);
        $state = [
            'pricing_type' => $mainId ? 'weight' : 'simple',
            'item_type' => ItemType::GOODS->value,
            'main_product_id' => $mainId,
            'base_price' => 0,
            'supplier_ids' => [],
            'apply_to_colors' => false,
            'apply_to_color_ids' => [],
        ];

        return [
            'id' => null,
            'state' => $state,
            'image' => null,
            'other_colors' => [],
            ...$this->context(null, $selectedCategoryId, $state),
        ];
    }

    /**
     * يحفظ البطاقة ويعيد الصنف. $state ما تحقق منه الطلب (دون الحقول المخفية).
     *
     * @param  array<string, mixed>  $state
     */
    public function save(?Product $product, array $state, ?int $selectedCategoryId, ?int $fixedMainId, ?UploadedFile $image = null, bool $removeImage = false): Product
    {
        // الحقول المقفلة ثابتة، ولا يغيّرها ما يصل من الواجهة.
        if ($fixedMainId) {
            $state = array_replace($state, [
                'main_product_id' => $fixedMainId,
                'pricing_type' => 'weight',
                'item_type' => ItemType::GOODS->value,
            ]);
        }

        $product = DB::transaction(function () use ($product, $state, $selectedCategoryId, $fixedMainId): Product {
            $product ??= new Product([
                'slug' => 'item-'.Str::lower(Str::random(10)),
                'is_active' => 1,
                'status' => 2,
                'manage_stock' => 1,
                'stock_status' => 'in_stock',
                'low_stock_threshold' => 10,
                'created_by' => auth()->id(),
            ]);

            $product->fill([
                'barcode' => $state['barcode'] ?? null,
                'pricing_type' => $state['pricing_type'],
                ...ItemCardRules::itemTypeAttributes($state),
                // الحقل المخفي لا يصل: يبقى السعر المحفوظ كما هو.
                'base_price' => $state['base_price'] ?? $product->base_price ?? 0,
                'length' => $state['length'] ?? null,
                'weight_per_meter' => $state['weight_per_meter'] ?? null,
                'synonym_product_id' => $state['synonym_product_id'] ?? null,
                'shelf' => $state['shelf'] ?? null,
                'color' => $state['color'] ?? null,
                'site_area' => $state['site_area'] ?? null,
                'unit_id' => $state['unit_id'] ?? null,
                ...ItemCardRules::itemModelAttributes($state),
                // الحدود لا تصل لمن لا يملك «تعديل الحدود»، فتبقى قيمها المحفوظة.
                ...Arr::only($state, StockLimits::FIELDS),
            ]);

            if (! $product->exists) {
                $product->sku = $this->newItemSku($state['main_product_id'] ?? null, $selectedCategoryId);
            }

            $product->save();
            $product->translateOrNew(app()->getLocale())->fill([
                'name' => $state['name'],
                'short_description' => $state['short_description'] ?? null,
            ]);
            $product->save();

            $product->suppliers()->sync($state['supplier_ids'] ?? []);

            if ($product->wasRecentlyCreated) {
                $this->attachCategories($product, $selectedCategoryId, $fixedMainId);
            }

            if (! empty($state['apply_to_colors'])) {
                $this->treeService->applyToOtherColors($product, $state['apply_to_color_ids'] ?? []);
            }

            return $product;
        });

        // الصورة بعد ان يصبح للصنف معرّف (صورة واحدة في مجموعة product).
        if ($image) {
            $product->addImage($image);
        } elseif ($removeImage) {
            $product->clearMediaCollection('product');
        }

        return $product;
    }

    /**
     * ما يُعرض حول الحقول: رقم الصنف الرئيسي او رقم الفئة بجانب "تابع لصنف"، واسم الفئة
     * الاب حين لا صنف رئيسي، وعناوين الاختيارات المحفوظة.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function context(?Product $product, ?int $selectedCategoryId, array $state): array
    {
        $parent = $this->tree->cardParentCategory($product, $selectedCategoryId);
        $mainId = $state['main_product_id'] ?? null;
        $synonymId = $state['synonym_product_id'] ?? null;

        return [
            'parent' => $parent ? ['code' => $parent->code, 'name' => (string) ($parent->name ?: $parent->slug)] : null,
            'main_sku' => $mainId ? Product::query()->whereKey($mainId)->value('sku') : null,
            'labels' => [
                'main_product_id' => $mainId ? [['value' => (int) $mainId, 'label' => (string) ProductSearch::label($mainId)]] : [],
                'synonym_product_id' => $synonymId ? [['value' => (int) $synonymId, 'label' => (string) ProductSearch::label($synonymId)]] : [],
            ],
            'can_edit_limits' => StockLimits::canEdit(),
            'options' => ItemCard::options($product),
        ];
    }

    /**
     * الصنف الجديد في التصنيف المختار، او في تصنيفات صنفه الرئيسي (المقطع المضاف من الشجرة
     * يتبع رئيسيه دائماً).
     */
    private function attachCategories(Product $product, ?int $selectedCategoryId, ?int $fixedMainId): void
    {
        $categoryIds = $selectedCategoryId && ! $fixedMainId
            ? [$selectedCategoryId]
            : ($product->main_product_id
                ? DB::table(config('items.table_prefix').'products_categories')->where('product_id', $product->main_product_id)->pluck('category_id')->all()
                : []);

        if ($categoryIds !== []) {
            $product->categories()->syncWithoutDetaching($categoryIds);
        }
    }

    /**
     * رقم الصنف الجديد كما في النظام القديم: رقم صنفه الرئيسي، والا رقم فئته، وبعده تسلسل من
     * ثلاث خانات. بلا رقم اب يُولَّد تلقائياً.
     */
    private function newItemSku(mixed $mainProductId, ?int $selectedCategoryId): ?string
    {
        $parentCode = filled($mainProductId)
            ? Product::query()->whereKey($mainProductId)->value('sku')
            : ($selectedCategoryId ? Category::query()->whereKey($selectedCategoryId)->value('code') : null);

        return filled($parentCode) ? ItemCode::nextChild($parentCode) : null;
    }
}
