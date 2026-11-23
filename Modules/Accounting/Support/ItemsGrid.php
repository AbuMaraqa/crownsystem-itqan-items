<?php

namespace Modules\Accounting\Support;

use Closure;
use DomainException;
use Modules\Accounting\Entities\StockBalance;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductAccessory;
use Modules\Items\Enums\ItemType;
use Modules\Items\Support\BarcodeResolver;
use Modules\Items\Support\CategorySearch;
use Modules\Items\Support\KgPriceResolver;
use Modules\Items\Support\LinePriceContext;
use Modules\Items\Support\PriceGroupSearch;
use Modules\Items\Support\PriceResolver;
use Modules\Items\Support\ProductSearch;
use Modules\Items\Support\ResolvedBarcode;
use Modules\Items\Support\Search\TextNormalizer;

/**
 * منطق جدول الاصناف الموحد على السيرفر: البحث، تفاصيل الصنف المختار وسعره للطرف، الباركود،
 * مطابقة المقاطع بالاسم، رصيد السطر وتكلفته، واسعار الطرف الجديد. مصدر واحد لجدول Vue
 * (ItemsGridController).
 *
 * التسعير يأتي من $payloads: سطور البيع او الشراء من ProductSearch بترتيب PriceResolver.
 */
final class ItemsGrid
{
    /**
     * @param  Closure(array<int, int>): array<int, array<string, mixed>>  $payloads  تفاصيل الاصناف مفهرسة بالمعرف
     * @param  bool  $accessories  اقتراح ملحقات الصنف المختار (مستندات البيع)
     */
    public function __construct(
        private readonly Closure $payloads,
        private readonly bool $accessories = false,
        private readonly ?LinePriceContext $context = null,
        private readonly string $side = PriceResolver::SELL,
    ) {}

    /**
     * جدول بجهة تسعير: البيع (سعر البيع او التكلفة + هامش الزبون) او الشراء (التكلفة)،
     * وكلاهما يمر بآخر سعر للطرف ثم قائمة اسعاره (PriceResolver::forDocumentLines).
     * سطر مجموعة السعر يُسعَّر بالكيلو (KgPriceResolver).
     */
    public static function for(string $side, LinePriceContext $context, bool $accessories = false): self
    {
        $payloads = $side === PriceResolver::BUY
            ? fn (array $ids): array => ProductSearch::invoiceItemPayloads($ids, $context)
            : fn (array $ids): array => ProductSearch::salesItemPayloads($ids, CustomerProfitMargin::for($context->partyId), $context);

        return new self($payloads, $accessories, $context, $side);
    }

    /**
     * بحث خانة الصنف: المعرف والاسم فقط، والتفاصيل تُجلب عند الاختيار. الخدمات لها قسمها.
     *
     * - مجموعات السعر (التصنيف × اللون) اولاً: تصير سطراً رئيسياً بالكيلو قطعه ألوان مقاطعه.
     * - ثم الاصناف. مقطع الوزن الذي له ألوان تحل محله ألوانه (يُباع اللون لا المقطع)، مع اسم مجموعتها.
     * - داخل صف مجموعة تصنيف قديم يقتصر البحث على اصناف التصنيف وفروعه.
     *
     * @return array<int, array{id: int, label: string, kind: string, main_label: ?string, group_label: ?string}>
     */
    public static function searchProducts(string $term, mixed $categoryId = null): array
    {
        $groups = filled($categoryId) ? [] : PriceGroupSearch::options($term, 8);
        $options = filled($categoryId)
            ? CategorySearch::productOptions($categoryId, $term, 25)
            : ProductSearch::options($term, 25, itemType: ItemType::GOODS->value);

        $serviceIds = Product::query()->whereIn('id', array_keys($options))->where('item_type', ItemType::SERVICE->value)->pluck('id')->all();
        $options = array_diff_key($options, array_flip($serviceIds));

        $colours = self::colourVariations(array_keys($options));
        // الصنف الفرعي القديم يظهر مع اسم صنفه الرئيسي لانه سيُضاف داخله.
        $mainLabels = ProductSearch::mainProductLabels(array_keys($options));
        $results = [];

        foreach ($groups as $groupId => $label) {
            $results[] = ['id' => (int) $groupId, 'label' => $label, 'kind' => 'group', 'main_label' => null, 'group_label' => null];
        }

        foreach ($options as $productId => $label) {
            foreach ($colours[$productId] ?? [[$productId, $label, null]] as [$id, $itemLabel, $groupLabel]) {
                $results[] = ['id' => (int) $id, 'label' => $itemLabel, 'kind' => 'item', 'main_label' => $mainLabels[$id] ?? null, 'group_label' => $groupLabel];
            }
        }

        return $results;
    }

    /**
     * ألوان مقاطع الوزن (متغيّراتها ذات مجموعة السعر) المفعّلة، لكل مقطع: [معرّف، اسم، اسم المجموعة].
     *
     * @param  array<int, int|string>  $productIds
     * @return array<int, list<array{0: int, 1: string, 2: string}>>
     */
    private static function colourVariations(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $variations = Product::query()
            ->select(['id', 'parent_id', 'category_finish_id'])
            ->whereIn('parent_id', $productIds)
            ->whereNotNull('category_finish_id')
            ->where('is_active', 1)
            ->orderBy('id')
            ->get();
        $labels = ProductSearch::labels($variations->modelKeys());
        $groups = PriceGroupSearch::labels($variations->pluck('category_finish_id')->unique()->all());

        return $variations
            ->groupBy('parent_id')
            ->map(fn ($rows) => $rows->map(fn (Product $variation) => [
                (int) $variation->id,
                $labels[$variation->id] ?? (string) $variation->id,
                $groups[$variation->category_finish_id] ?? '',
            ])->values()->all())
            ->all();
    }

    /**
     * سطر مجموعة السعر كما يدخل الجدول: اسمها وسعر الكيلو للطرف.
     *
     * @return array{id: int, label: string, unit_price: string, pricing_type: string}|null
     */
    public function groupPayload(mixed $groupId): ?array
    {
        $label = filled($groupId) ? (PriceGroupSearch::labels([$groupId])[(int) $groupId] ?? null) : null;

        if ($label === null) {
            return null;
        }

        return [
            'id' => (int) $groupId,
            'label' => $label,
            'unit_price' => $this->groupPrices([$groupId])[(int) $groupId] ?? '0.0000',
            'pricing_type' => 'weight',
        ];
    }

    /**
     * سعر الكيلو لكل مجموعة سعر للطرف الحالي (يُعاد تسعير سطورها عند تغيير الطرف).
     *
     * @param  array<int, mixed>  $groupIds
     * @return array<int, string>
     */
    public function groupPrices(array $groupIds): array
    {
        $context = $this->context ?? new LinePriceContext(null);

        return collect(KgPriceResolver::forGroups(array_filter($groupIds, 'filled'), $context, $this->side))
            ->map(fn (float $price): string => number_format($price, 4, '.', ''))
            ->all();
    }

    /**
     * بحث المجموعات (التصنيفات) في تبويب "مجموعة".
     *
     * @return array<int, array{id: int, label: string}>
     */
    public static function searchCategories(string $term): array
    {
        return self::optionResults(CategorySearch::options($term, 25));
    }

    /**
     * اصناف القطع الناتجة عن صنف المصدر (وصفة التقطيع). بلا مصدر: داخل صنف رئيسي له اصناف
     * فرعية يقتصر البحث عليها، والا فبحث في اصناف الوزن.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public static function searchPieceOutputs(mixed $sourceProductId, string $term, mixed $mainProductId = null, mixed $groupId = null): array
    {
        // داخل سطر مجموعة سعر: مقاطع التصنيف الموجودة بهذا اللون فقط.
        if (blank($sourceProductId) && filled($groupId)) {
            return self::optionResults(self::groupVariationOptions((int) $groupId, $term));
        }

        if (blank($sourceProductId)) {
            if (filled($mainProductId) && ProductSearch::idsWithSubProducts([(int) $mainProductId]) !== []) {
                return self::optionResults(ProductSearch::subProductOptions($mainProductId, $term, 25));
            }

            return self::optionResults(ProductSearch::options($term, 25, 'weight'));
        }

        $options = ProductSearch::cuttingRecipeOutputOptions($sourceProductId);

        if (filled($term)) {
            $needle = mb_strtolower($term);
            $options = array_filter($options, fn ($label) => str_contains(mb_strtolower($label), $needle));
        }

        return self::optionResults(array_slice($options, 0, 25, true));
    }

    /**
     * ألوان مجموعة السعر (متغيّرات مقاطعها) المفعّلة، مطابقة لكل كلمات البحث في الاسم او الرقم او الباركود.
     *
     * @return array<int, string>
     */
    private static function groupVariationOptions(int $groupId, string $term, int $limit = 25): array
    {
        $ids = Product::query()->where('category_finish_id', $groupId)->where('is_active', 1)->whereNotNull('parent_id')->orderBy('id')->pluck('id')->all();
        $labels = ProductSearch::labels($ids);
        $words = TextNormalizer::words($term);

        if ($words !== []) {
            $labels = array_filter($labels, function (string $label) use ($words): bool {
                $text = TextNormalizer::normalize($label);

                return collect($words)->every(fn (string $word): bool => str_contains($text, $word));
            });
        }

        return array_slice($labels, 0, $limit, true);
    }

    /**
     * تفاصيل الصنف المختار، وملحقاته المقترحة ان كان سطراً لا قطعة داخل صنف رئيسي.
     *
     * @return array<string, mixed>|null
     */
    public function itemPayload(mixed $productId, mixed $sourceProductId = null): ?array
    {
        $payload = $this->productPayload($productId, $sourceProductId);

        if ($payload && blank($sourceProductId) && $this->accessories) {
            $payload['accessories'] = $this->accessories($payload['id']);
        }

        return $payload;
    }

    /**
     * ملحقات الصنف المعرّفة في بطاقته بكميتها المقترحة لكل وحدة. الخدمات تُستبعد.
     *
     * @return array<int, array{quantity: string, product: array<string, mixed>}>
     */
    public function accessories(int $productId): array
    {
        $accessories = ProductAccessory::query()
            ->where('product_id', $productId)
            ->whereHas('accessoryProduct', fn ($query) => $query->where('item_type', '!=', ItemType::SERVICE->value))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['accessory_product_id', 'quantity']);

        $results = [];

        foreach ($accessories as $accessory) {
            $product = $this->productPayload($accessory->accessory_product_id);

            if ($product) {
                $results[] = [
                    'quantity' => number_format((float) $accessory->quantity, 4, '.', ''),
                    'product' => $product,
                ];
            }
        }

        return $results;
    }

    /**
     * تفاصيل صنف كما يدخل الجدول: سعره للطرف، ونوع تسعيره، وقياسات القطعة، وصنفه الرئيسي
     * (الصنف الفرعي يُضاف داخل بند صنفه الرئيسي وعليه سعر الكيلوغرام).
     *
     * @return array<string, mixed>|null
     */
    public function productPayload(mixed $productId, mixed $sourceProductId = null): ?array
    {
        if (blank($productId)) {
            return null;
        }

        $productId = (int) $productId;
        $payload = $this->payloadOf($productId);

        if (! $payload) {
            return null;
        }

        $mainPayload = filled($payload['main_product_id'] ?? null) ? $this->payloadOf((int) $payload['main_product_id']) : null;

        return [
            'id' => $productId,
            'label' => $payload['label'] ?? ProductSearch::label($productId),
            'barcode' => $payload['barcode'] ?? null,
            'description' => $payload['description'] ?? null,
            'unit_price' => $payload['unit_price'] ?? '0.0000',
            'pricing_type' => $payload['pricing_type'] ?? 'simple',
            'length' => $payload['length'] ?? null,
            'weight_per_meter' => $payload['weight_per_meter'] ?? null,
            'image' => $payload['image'] ?? null,
            'piece_count' => filled($sourceProductId)
                ? ProductSearch::cuttingRecipePiecesPerUnit($sourceProductId, $productId)
                : null,
            'has_sub_products' => (bool) ($payload['has_sub_products'] ?? false),
            // لون مقطع: يُضاف داخل سطر مجموعة سعره، وعليها سعر الكيلو.
            'group' => filled($payload['category_finish_id'] ?? null) ? $this->groupPayload($payload['category_finish_id']) : null,
            'main_product' => $mainPayload ? [
                'id' => (int) $mainPayload['id'],
                'label' => $mainPayload['label'] ?? ProductSearch::label($mainPayload['id']),
                'barcode' => $mainPayload['barcode'] ?? null,
                'description' => $mainPayload['description'] ?? null,
                'unit_price' => $mainPayload['unit_price'] ?? '0.0000',
                'pricing_type' => $mainPayload['pricing_type'] ?? 'weight',
            ] : null,
        ];
    }

    /**
     * حقل الباركود: كل الاصناف المطابقة (الباركود يتكرر بين الالوان عمداً) بكمية عبوة كل
     * منها. الخدمات لها قسمها. بلا مطابقة يعود رابط اضافة الصنف لمن له الصلاحية.
     *
     * @return array{matches: array<int, array<string, mixed>>, add_url: string|null}
     */
    public static function resolveBarcode(string $code): array
    {
        $resolved = collect(BarcodeResolver::resolveAll($code))
            ->reject(fn (ResolvedBarcode $match): bool => $match->product->isService())
            ->unique(fn (ResolvedBarcode $match): int => (int) $match->product->id)
            ->values();

        $ids = $resolved->map(fn (ResolvedBarcode $match): int => (int) $match->product->id)->all();
        $labels = ProductSearch::labels($ids);
        $mainLabels = ProductSearch::mainProductLabels($ids);

        return [
            'matches' => $resolved->map(fn (ResolvedBarcode $match): array => [
                'id' => (int) $match->product->id,
                'label' => $labels[(int) $match->product->id] ?? $match->product->name,
                'main_label' => $mainLabels[(int) $match->product->id] ?? null,
                'pack_quantity' => $match->packQuantity,
            ])->all(),
            'add_url' => $ids === [] && auth()->user()?->can('Product Create') ? route('products.add') : null,
        ];
    }

    /**
     * مطابقة مقاطع السطر بالاسم تحت الصنف الرئيسي الجديد. كل مقطع يأخذ مطابقاً واحداً،
     * وما لم يجد يُعاد باسمه ليُعرض للمستخدم.
     *
     * @param  array<int, mixed>  $pieceProductIds
     * @return array{matched: array<int, array{source_id: int, product: array<string, mixed>}>, unmatched: array<int, string>}
     */
    public function matchPiecesByName(mixed $mainProductId, array $pieceProductIds): array
    {
        $candidates = Product::query()
            ->with('translations')
            ->where('main_product_id', (int) $mainProductId)
            ->orderBy('sku')
            ->get()
            ->groupBy(fn (Product $product): string => trim((string) $product->name))
            ->map(fn ($group) => $group->pluck('id')->all())
            ->all();

        $sources = Product::query()->with('translations')->whereIn('id', $pieceProductIds)->get()->keyBy('id');
        $matched = [];
        $unmatched = [];

        foreach ($pieceProductIds as $sourceId) {
            $name = trim((string) $sources->get((int) $sourceId)?->name);

            if (empty($candidates[$name])) {
                $unmatched[] = $name;

                continue;
            }

            $payload = $this->productPayload(array_shift($candidates[$name]));

            if ($payload) {
                $matched[] = ['source_id' => (int) $sourceId, 'product' => $payload];
            }
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * رصيد الصنف وتكلفته للسطر الذي عليه المؤشر، كما يعرضهما اتقان بجانب الفاتورة. الصنف
     * الرئيسي رصيده كغمه، والمقطع رصيده عدد قطعه. التكلفة لمن يعدّل الصنف فقط.
     *
     * @return array{product_id: int, label: ?string, here: ?string, all: string, tone: ?string, status: ?string, unit: string, cost: ?string}|null
     */
    public static function itemStock(mixed $productId, mixed $warehouseId = null): ?array
    {
        $user = auth()->user();
        $product = filled($productId) ? Product::query()->find((int) $productId) : null;

        if (! $product || ! $user?->can('Stock View List')) {
            return null;
        }

        // لون المقطع (متغيّر مجموعة سعر) يُعدّ بالقطع كالمقطع القديم.
        $isMain = $product->pricing_type === 'weight' && blank($product->main_product_id) && blank($product->category_finish_id);
        $isPiece = $product->pricing_type === 'weight' && ! $isMain;
        $warehouseId = filled($warehouseId) ? (int) $warehouseId : null;

        $sum = fn (?int $warehouse): float => (float) StockBalance::query()
            ->where('product_id', $product->id)
            ->when($warehouse, fn ($query) => $query->where('warehouse_id', $warehouse))
            ->sum($isMain ? 'weight' : 'quantity');
        $balance = fn (?int $warehouse): string => number_format($sum($warehouse), 2, '.', '');
        $total = $sum(null);

        // رصيد كل المخازن عند حده الادنى 1 او 2 او نافد يُلوَّن بلون حالته؛ كغم الصنف الرئيسي لا يقارَن بحدود.
        $status = ! $isMain && StockLimits::isMonitored($product) ? StockStatus::for($product, $total) : null;
        $low = in_array($status, [StockStatus::OUT, StockStatus::CRITICAL, StockStatus::LOW], true);

        $cost = $isPiece || ! $user->can('Product Update')
            ? null
            : app(StockLedgerService::class)->averageCost($product->id);

        return [
            'product_id' => $product->id,
            'label' => ProductSearch::label($product->id),
            'here' => $warehouseId ? $balance($warehouseId) : null,
            'all' => number_format($total, 2, '.', ''),
            'tone' => $low ? StatusBadge::tone($status->color()) : null,
            'status' => $low ? $status->getLabel() : null,
            'unit' => $isMain ? __('kg') : ($isPiece ? __('pcs') : ''),
            'cost' => $cost === null ? null : number_format($cost, 2, '.', ''),
        ];
    }

    /**
     * سعر كل صنف للطرف الحالي، بنفس ترتيب تسعير السطر الجديد.
     *
     * @param  array<int, mixed>  $productIds
     * @return array<int, string>
     */
    public function linePrices(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        return collect(($this->payloads)($ids))
            ->map(fn (array $payload): string => (string) ($payload['unit_price'] ?? '0.0000'))
            ->all();
    }

    /**
     * صنف الوزن صنف رئيسي لا يكتمل بلا اصناف فرعية، وكل صنف فرعي جديد له وزن (عدد القطع ×
     * طول القطعة × وزن المتر). البنود والاصناف الفرعية المحفوظة سابقاً (لها id) تُترك، لان
     * تحويل عرض السعر الى طلبية ينشئ بنوداً بلا اصناف فرعية. الخدمة (ولو سُعِّرت بالكيلوغرام)
     * ليست صنفاً رئيسياً: تُسعَّر بكميتها.
     *
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws DomainException
     */
    public static function ensureMainItemsHaveSubItems(array $items): void
    {
        self::ensureGroupLinesHaveTheirColours($items);

        $items = collect($items)->filter(fn ($item): bool => is_array($item) && filled($item['product_id'] ?? null));

        $weightIds = Product::query()
            ->whereIn('id', $items->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->values()->all())
            ->where('pricing_type', 'weight')
            ->where(fn ($query) => $query->whereNull('item_type')->orWhere('item_type', '!=', ItemType::SERVICE->value))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($items as $item) {
            if (! in_array((int) $item['product_id'], $weightIds, true)) {
                continue;
            }

            $subItems = collect($item['pieces'] ?? [])->filter(fn ($piece): bool => is_array($piece) && filled($piece['product_id'] ?? null));
            $label = fn (): ?string => $item['product_label'] ?? ProductSearch::label($item['product_id']);

            if (blank($item['id'] ?? null) && $subItems->isEmpty()) {
                throw new DomainException(__('Add the sub-items of the main item ":item"; its total weight is the sum of their weights.', [
                    'item' => $label(),
                ]));
            }

            $weightless = $subItems->first(fn (array $piece): bool => blank($piece['id'] ?? null) && WeightPieces::weight($piece) <= 0);

            if ($weightless) {
                throw new DomainException(__('The sub-item ":sub" in ":item" has no weight; enter its piece count, piece length and weight per meter.', [
                    'sub' => $weightless['product_label'] ?? ProductSearch::label($weightless['product_id']),
                    'item' => $label(),
                ]));
            }
        }
    }

    /**
     * سطر مجموعة السعر الجديد («الوبين — أبيض») لا يُحفظ بلا قطع لها وزن، وكل قطعه ألوان هذه
     * المجموعة: لون آخر يُسعَّر بسعر كيلو غير سعره. السطور والقطع المحفوظة سابقاً (لها id) تُترك
     * لقاعدة الوزن كما في الصنف الرئيسي.
     *
     * @param  array<int, mixed>  $items
     *
     * @throws DomainException
     */
    private static function ensureGroupLinesHaveTheirColours(array $items): void
    {
        $lines = collect($items)->filter(fn ($item): bool => is_array($item) && GridLine::groupId($item) !== null);

        if ($lines->isEmpty()) {
            return;
        }

        $labels = PriceGroupSearch::labels($lines->map(fn (array $item): int => GridLine::groupId($item))->all());
        $pieceIds = $lines->flatMap(fn (array $item) => collect($item['pieces'] ?? [])->pluck('product_id'))->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $groupOf = Product::query()->whereIn('id', $pieceIds)->pluck('category_finish_id', 'id');

        foreach ($lines as $item) {
            $groupId = GridLine::groupId($item);
            $group = $labels[$groupId] ?? (string) $groupId;
            $pieces = collect($item['pieces'] ?? [])->filter(fn ($piece): bool => is_array($piece) && filled($piece['product_id'] ?? null));

            // سطر المجموعة بلا صنف رئيسي: بلا قطع بوزن لا يتحرك شيء في المخزون، جديداً كان او محفوظاً.
            if ($pieces->isEmpty()) {
                throw new DomainException(__('Add the sub-items of the main item ":item"; its total weight is the sum of their weights.', ['item' => $group]));
            }

            $foreign = $pieces->first(fn (array $piece): bool => (int) ($groupOf[(int) $piece['product_id']] ?? 0) !== $groupId);

            if ($foreign) {
                throw new DomainException(__('":sub" is not in ":group"; add it under its own colour.', [
                    'sub' => $foreign['product_label'] ?? ProductSearch::label($foreign['product_id']),
                    'group' => $group,
                ]));
            }

            $weightless = $pieces->first(fn (array $piece): bool => WeightPieces::weight($piece) <= 0);

            if ($weightless) {
                throw new DomainException(__('The sub-item ":sub" in ":item" has no weight; enter its piece count, piece length and weight per meter.', [
                    'sub' => $weightless['product_label'] ?? ProductSearch::label($weightless['product_id']),
                    'item' => $group,
                ]));
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payloadOf(int $productId): ?array
    {
        return ($this->payloads)([$productId])[$productId] ?? null;
    }

    /**
     * @param  array<int, string>  $options
     * @return array<int, array{id: int, label: string}>
     */
    private static function optionResults(array $options): array
    {
        $results = [];

        foreach ($options as $productId => $label) {
            $results[] = ['id' => (int) $productId, 'label' => $label];
        }

        return $results;
    }
}
