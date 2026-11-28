<?php

namespace Modules\Items\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\StockLedgerService;
use Modules\Core\Support\Search\KeyboardLayout;
use Modules\Core\Support\Search\SearchIndex;
use Modules\Items\Entities\Product;
use Modules\Items\Entities\ProductCuttingRecipe;
use Modules\Items\Enums\ItemType;
use Modules\Items\Support\Search\MatchMode;
use Modules\Items\Support\Search\SearchCapabilities;
use Modules\Items\Support\Search\SearchFilters;
use Modules\Items\Support\Search\SearchQuery;
use Modules\Items\Support\Search\SearchResult;
use Modules\Items\Support\Search\SearchScorer;

/**
 * البحث في الاصناف، وتستخدمه كل الشاشات: قائمة الاصناف وشجرتها، وقوائم
 * اختيار الصنف في اوامر الشراء والبيع والفواتير.
 *
 * البحث على مراحل، من الادق والاسرع الى الاوسع:
 *  1. رقم الصنف او الباركود (الرئيسي والاضافية) كما كُتب، ثم ما يبدأ به (فهرس BTREE).
 *  2. الاسم الذي يبدأ بنص البحث (فهرس BTREE على الترجمات).
 *  3. فهرس البحث (search_text) بـ FULLTEXT: كل الكلمات بأي ترتيب، وفي اي حقل
 *     (الاسم، الرقم، الباركود، الرف، المرادف، التصنيف، العلامة، المصنّع).
 *  4. ان لم يُعثر على شيء: الكلمات وسط الكلمات، ثم ما طابق بعض الكلمات،
 *     ثم نص البحث نفسه بعد تصحيح لغة لوحة المفاتيح.
 * ثم يُرتَّب المرشحون بالاقرب (SearchScorer).
 */
class ProductSearch
{
    /**
     * عدد المطابقات الذي يصبح بعده البحث "واسعاً" في PostgreSQL. حوله يتساوى الشكلان في
     * whereMatches(): ربط كل مطابقة بصفها (نحو 0.013ms للصف) ومرور واحد على جدول الاصناف
     * (نحو 350ms ثابتة) على 368 الف صنف.
     */
    private const BROAD_MATCHES = 25000;

    /**
     * اكثر عدد من المطابقات يُجلب معرّفاته الى apply() (قائمة IN نحو 350KB). كلمة شائعة على 300 الف
     * صنف تطابق نحو 40 الفاً؛ ما فوق الحد يبقى استعلاماً فرعياً.
     */
    private const FETCHED_MATCHES = 50000;

    /**
     * @var array<int, string>
     */
    private static array $labelCache = [];

    /**
     * قصر استعلام جدول (كجداول Filament) على الاصناف المطابقة، بلا حد لعددها.
     * المطابقة بالكلمات، فإن لم يطابق شيء فبالكلمات وسط الكلمات، ثم بعد
     * تصحيح لغة لوحة المفاتيح.
     */
    public static function apply(Builder $query, string $search): Builder
    {
        $searchQuery = SearchQuery::from($search);

        if ($searchQuery->isEmpty()) {
            return $query;
        }

        $attempts = [[$searchQuery, MatchMode::Words]];

        if (($swapped = KeyboardLayout::swap($searchQuery->raw)) !== null) {
            $attempts[] = [SearchQuery::from($swapped), MatchMode::Words];
        }

        $attempts[] = [$searchQuery, MatchMode::Contains];

        foreach ($attempts as $index => [$attempt, $mode]) {
            $engineIds = $mode === MatchMode::Words ? self::engineIds($attempt, $mode, SearchIndex::MAX_HITS + 1) : null;

            // محرك البحث يغني عن فهرس قاعدة البيانات اذا اعاد كل المطابقات (دون الحد)؛
            // الرقم والباركود وبداية الاسم تبقى من قاعدة البيانات (فهارس BTREE سريعة).
            if ($engineIds !== null && count($engineIds) <= SearchIndex::MAX_HITS) {
                $ids = self::matchedIds(self::matchingIdQueries($attempt, $mode, withIndex: false));
                $ids = $ids === null ? null : self::mergeIds($ids, $engineIds);

                if ($ids === [] && $index !== array_key_last($attempts)) {
                    continue;
                }

                if ($ids !== null) {
                    return $query->whereIntegerInRaw($query->qualifyColumn('id'), $ids);
                }
            }

            $parts = self::matchingIdQueries($attempt, $mode);
            $ids = self::matchedIds($parts);

            // المحاولة الاخيرة تُطبَّق ولو لم تطابق شيئاً، فيظهر الجدول فارغاً.
            if ($ids === [] && $index !== array_key_last($attempts)) {
                continue;
            }

            return $ids === null ? self::whereMatches($query, $parts) : $query->whereIntegerInRaw($query->qualifyColumn('id'), $ids);
        }

        return $query;
    }

    /**
     * معرّفات المطابقات تُجلب مرة واحدة، فيعدّ الجدول صفوفه ويجلب صفحته منها دون أن يعيد البحث
     * (LIKE وسط الكلمات يمر على الفهرس كله في كل مرة)، وتغني عن سؤال «هل طابق شيء؟» قبله.
     * فوق FETCHED_MATCHES: null، فيبقى البحث استعلاماً فرعياً (whereMatches) بدل قائمة ضخمة.
     *
     * @param  array<int, QueryBuilder>  $parts
     * @return list<int>|null
     */
    private static function matchedIds(array $parts): ?array
    {
        $union = clone $parts[0];

        foreach (array_slice($parts, 1) as $part) {
            $union->unionAll($part);
        }

        $ids = DB::query()->fromSub($union, 'matches')->limit(self::FETCHED_MATCHES + 1)->pluck('id');

        if ($ids->count() > self::FETCHED_MATCHES) {
            return null;
        }

        return $ids->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * قصر الاستعلام على المعرّفات التي تعيدها الاجزاء.
     *
     * الشكل المعتاد: IN على UNION ALL للاجزاء، فيُحسب الجدول المشتق مرة ويُربط كل
     * معرّف بصفه. لكن PostgreSQL لا يعرف عدد المعرّفات المميزة في UNION فيقدّرها 200،
     * فإن طابقت كلمة شائعة مئات الآلاف رُبطت واحداً واحداً (ثوانٍ). فللبحث الواسع
     * فيه: IN لكل جزء مفصولة بـ OR، فيمر على جدول الاصناف مرة مقابل جداول تجزئة.
     *
     * @param  array<int, QueryBuilder>  $parts
     */
    private static function whereMatches(Builder $query, array $parts): Builder
    {
        $id = $query->qualifyColumn('id');

        // UNION يُبنى على نسخة من الجزء الاول، فتبقى الاجزاء كما هي للشكل الآخر.
        $union = clone $parts[0];

        foreach (array_slice($parts, 1) as $part) {
            $union->unionAll($part);
        }

        if (DB::getDriverName() === 'pgsql' && self::isBroad($union)) {
            return $query->where(function (Builder $query) use ($id, $parts) {
                foreach ($parts as $part) {
                    $query->orWhereIn($id, $part);
                }
            });
        }

        return $query->whereIn($id, DB::query()->fromSub($union, 'matches')->select('matches.id'));
    }

    /**
     * هل تبلغ المطابقات BROAD_MATCHES؟ العدّ يتوقف عند الحد، فالكلمة الشائعة لا تمر على كل شيء.
     */
    private static function isBroad(QueryBuilder $union): bool
    {
        return DB::query()->fromSub((clone $union)->limit(self::BROAD_MATCHES), 'probe')->count() >= self::BROAD_MATCHES;
    }

    /**
     * ترتيب استعلام الجدول: الرقم او الباركود كما كُتب، ثم ما يبدأ به. يُستدعى
     * حين لا يختار المستخدم ترتيباً آخر.
     *
     * الترتيب من اعمدة الصف نفسه فقط: الجدول يرتب كل المطابقات (عشرات الآلاف
     * لكلمة شائعة)، وايّ استعلام فرعي لكل صف يضاعف زمنه. الترتيب الادق بالاسم
     * والكلمات في find() لقلّة مرشحيه.
     */
    public static function orderByRelevance(Builder $query, string $search): Builder
    {
        $searchQuery = SearchQuery::from($search);

        if ($searchQuery->isEmpty()) {
            return $query;
        }

        $sku = $query->qualifyColumn('sku');
        $barcode = $query->qualifyColumn('barcode');
        $like = SearchQuery::escapeLike($searchQuery->raw).'%';

        return $query->orderByRaw(
            "CASE WHEN {$sku} = ? OR {$barcode} = ? THEN 0 WHEN {$sku} LIKE ? OR {$barcode} LIKE ? THEN 1 ELSE 2 END",
            [$searchQuery->raw, $searchQuery->raw, $like, $like]
        );
    }

    /**
     * الاصناف المطابقة مرتبةً بالاقرب، مع وصف ما جرى (تصحيح اللغة، مطابقة جزئية).
     *
     * المحاولات من الاسرع والادق: الكلمات، ثم النص بعد تصحيح لغة لوحة المفاتيح،
     * ثم الكلمات وسط الكلمات (يمر على الفهرس كله)، ثم ما طابق بعض الكلمات.
     */
    public static function find(?string $search, int $limit = 50, ?SearchFilters $filters = null): SearchResult
    {
        $query = SearchQuery::from($search);
        $filters ??= new SearchFilters;
        $limit = max($limit, 1);

        if ($query->isEmpty()) {
            return new SearchResult($query, []);
        }

        $result = self::run($query, MatchMode::Words, $limit, $filters);

        if (! $result->isEmpty()) {
            return $result;
        }

        if (($swapped = KeyboardLayout::swap($query->raw)) !== null) {
            $corrected = self::run(SearchQuery::from($swapped), MatchMode::Words, $limit, $filters);

            if (! $corrected->isEmpty()) {
                return $corrected->correctedFrom($query->raw);
            }
        }

        $result = self::run($query, MatchMode::Contains, $limit, $filters);

        if ($result->isEmpty() && count($query->terms) > 1) {
            $result = self::run($query, MatchMode::AnyWord, $limit, $filters);
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public static function options(string $search = '', int $limit = 50, ?string $pricingType = null, ?string $itemType = null): array
    {
        $ids = self::find($search, $limit, new SearchFilters(pricingType: $pricingType, itemType: $itemType))->ids;

        return self::labels($ids);
    }

    /**
     * الخدمات معدودة، فبلا نص بحث تُعرض اوائلها ليختار المستخدم منها مباشرة.
     *
     * @return array<int, string>
     */
    public static function serviceOptions(string $search = '', int $limit = 50): array
    {
        if (trim($search) !== '') {
            return self::options($search, $limit, itemType: ItemType::SERVICE->value);
        }

        $ids = Product::query()
            ->where('item_type', ItemType::SERVICE->value)
            ->whereNull('parent_id')
            ->orderBy('sku')
            ->limit($limit)
            ->pluck('id')
            ->all();

        return self::labels($ids);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    public static function labels(array $ids): array
    {
        $ids = collect($ids)
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $missingIds = array_values(array_diff($ids, array_keys(self::$labelCache)));

        if ($missingIds !== []) {
            self::baseQuery()
                ->whereIn('id', $missingIds)
                ->get()
                ->each(fn (Product $product) => self::rememberLabel($product));
        }

        return collect($ids)
            ->filter(fn (int $id): bool => isset(self::$labelCache[$id]))
            ->mapWithKeys(fn (int $id): array => [$id => self::$labelCache[$id]])
            ->toArray();
    }

    public static function label(mixed $id): ?string
    {
        if (blank($id)) {
            return null;
        }

        return self::labels([$id])[(int) $id] ?? null;
    }

    /**
     * @return array{id: int, label: string, barcode: string|null, description: string|null, unit_price: string, weight_per_meter: string|null, length: string|null}|null
     */
    public static function invoiceItemPayload(mixed $id, ?LinePriceContext $context = null): ?array
    {
        if (blank($id)) {
            return null;
        }

        return self::invoiceItemPayloads([$id], $context)[(int) $id] ?? null;
    }

    /**
     * Purchase-side payloads for many products in a single query.
     *
     * مع سياق المستند يُسعَّر السطر عبر PriceResolver::forDocumentLines، وبلا سياق (خارج
     * سطور المستندات) يُقترح متوسط التكلفة المرجّح من المشتريات السابقة. السعر الاساسي سعر
     * بيع فلا يُقترح سعراً للشراء.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, array<string, mixed>>
     */
    public static function invoiceItemPayloads(array $ids, ?LinePriceContext $context = null): array
    {
        return self::payloadsFor(
            $ids,
            fn (Product $product, float $cost): float => $cost,
            $context,
            PriceResolver::BUY,
        );
    }

    /**
     * @return array{id: int, label: string, barcode: string|null, description: string|null, unit_price: string, weight_per_meter: string|null, length: string|null, image: string|null}|null
     */
    public static function salesItemPayload(mixed $id, ?float $profitMargin = null, ?LinePriceContext $context = null): ?array
    {
        if (blank($id)) {
            return null;
        }

        return self::salesItemPayloads([$id], $profitMargin, $context)[(int) $id] ?? null;
    }

    /**
     * Sales-side payloads for many products in a single query.
     *
     * نسبة ربح الزبون (ان وُجدت) تُضاف على متوسط التكلفة المرجّح؛ الصنف بلا تكلفة يبقى على سعره الاساسي.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, array<string, mixed>>
     */
    public static function salesItemPayloads(array $ids, ?float $profitMargin = null, ?LinePriceContext $context = null): array
    {
        return self::payloadsFor(
            $ids,
            fn (Product $product, float $cost): float => self::salesUnitPrice($product, $cost, $profitMargin),
            $context,
            PriceResolver::SELL,
        );
    }

    /**
     * سعر البيع المقترح: متوسط التكلفة + نسبة ربح الزبون، والا السعر الاساسي (السعر الوحيد في بطاقة الصنف).
     */
    public static function salesUnitPrice(Product $product, float $averageCost, ?float $profitMargin = null): float
    {
        if ($profitMargin !== null && $averageCost > 0) {
            return round($averageCost * (1 + $profitMargin / 100), 4);
        }

        return (float) ($product->base_price ?: 0);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @param  Closure(Product, float): float  $unitPrice  السعر من الصنف ومتوسط تكلفته
     * @return array<int, array<string, mixed>>
     */
    private static function payloadsFor(array $ids, Closure $unitPrice, ?LinePriceContext $context = null, string $side = PriceResolver::SELL): array
    {
        $ids = collect($ids)
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $mainIds = self::idsWithSubProducts($ids);

        $products = self::baseQuery()
            // media is eager loaded because $product->image resolves through it,
            // and these payloads are built for whole categories at a time.
            ->with('media')
            ->whereIn('id', $ids)
            ->get();

        // التكلفة من المشتريات الفعلية (المتوسط المرجّح)، لا من رقم يُكتب في بطاقة الصنف. قد يسعّر
        // PriceResolver صنفاً رئيسياً ليس في القائمة، فتُجلب تكلفته عند الحاجة.
        $ledger = app(StockLedgerService::class);
        $costs = $ledger->averageCosts($ids);
        $cost = function (int $id) use (&$costs, $ledger): float {
            if (! array_key_exists($id, $costs)) {
                $costs += $ledger->averageCosts([$id]);
            }

            return $costs[$id] ?? 0.0;
        };
        $priceOf = fn (Product $product): float => $unitPrice($product, $cost((int) $product->id));

        $linePrices = $context ? PriceResolver::forDocumentLines($products, $context, $side, $priceOf) : null;

        if ($linePrices !== null) {
            $priceOf = fn (Product $product): float => $linePrices[(int) $product->id] ?? 0.0;
        }

        return $products
            ->mapWithKeys(fn (Product $product): array => [
                (int) $product->id => [
                    'id' => (int) $product->id,
                    'label' => self::rememberLabel($product),
                    'barcode' => $product->barcode,
                    'description' => $product->name,
                    'unit_price' => number_format($priceOf($product), 4, '.', ''),
                    'weight_per_meter' => filled($product->weight_per_meter) ? number_format((float) $product->weight_per_meter, 4, '.', '') : null,
                    'length' => filled($product->length) ? number_format((float) $product->length, 4, '.', '') : null,
                    'image' => $product->image ?: null,
                    'pricing_type' => $product->pricing_type ?: 'simple',
                    'item_type' => $product->item_type ?: ItemType::GOODS->value,
                    'service_kind' => $product->service_kind,
                    'service_cost_source' => $product->service_cost_source,
                    'unit_cost' => number_format($cost((int) $product->id), 4, '.', ''),
                    // الصنف الفرعي لا يُضاف بنداً مستقلاً بل داخل صنفه الرئيسي.
                    'main_product_id' => $product->main_product_id ? (int) $product->main_product_id : null,
                    // لون صنف الوزن: يُضاف داخل سطر مجموعة سعره (التصنيف × اللون).
                    'category_finish_id' => $product->category_finish_id ? (int) $product->category_finish_id : null,
                    'has_sub_products' => in_array((int) $product->id, $mainIds, true),
                ],
            ])
            ->all();
    }

    /**
     * الاصناف الفرعية لصنف رئيسي، مفلترة بالاسم او الكود او الباركود.
     *
     * اصناف الصنف الرئيسي الواحد معدودة، فالبحث بجزء من الاسم في اي موضع منه
     * ممكن هنا دون كلفة البحث في جدول الاصناف كله.
     *
     * @return array<int, string>
     */
    public static function subProductOptions(mixed $mainProductId, string $search = '', int $limit = 25): array
    {
        if (blank($mainProductId)) {
            return [];
        }

        $searchQuery = SearchQuery::from($search);
        $like = '%'.SearchQuery::escapeLike($searchQuery->raw).'%';

        return self::baseQuery()
            ->where('main_product_id', (int) $mainProductId)
            // كل صنف داخل الصنف الرئيسي صنف وزن.
            ->where('pricing_type', 'weight')
            ->when(! $searchQuery->isEmpty(), fn (Builder $query) => $query->where(function (Builder $query) use ($like, $searchQuery) {
                $query
                    ->where('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhereHas('translations', fn (Builder $translationQuery) => $translationQuery
                        ->whereIn('locale', self::searchLocales())
                        ->where('name', 'like', $like));

                // بالفهرس: الكلمات بأي ترتيب، وبلا فرق في الهمزات والتاء المربوطة.
                if (SearchCapabilities::hasProductIndex()) {
                    $index = DB::table(SearchCapabilities::productTable().' as search')->select('search.product_id');
                    self::matchIndex($index, 'search.'.SearchCapabilities::DOCUMENT, $searchQuery, MatchMode::Contains);
                    $query->orWhereIn($query->qualifyColumn('id'), $index);
                }
            }))
            ->catalogOrder()
            ->limit(max($limit, 1))
            ->get()
            ->mapWithKeys(fn (Product $product): array => [(int) $product->id => self::rememberLabel($product)])
            ->all();
    }

    /**
     * اسم الصنف الرئيسي لكل صنف فرعي من الاصناف المعطاة، مفهرساً بالصنف الفرعي.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    public static function mainProductLabels(array $ids): array
    {
        $mainIds = Product::query()
            ->whereIn('id', collect($ids)->filter(fn ($id): bool => filled($id))->map(fn ($id): int => (int) $id)->all())
            ->whereNotNull('main_product_id')
            ->pluck('main_product_id', 'id')
            ->map(fn ($id): int => (int) $id);

        $labels = self::labels($mainIds->values()->all());

        return $mainIds
            ->map(fn (int $mainId): ?string => $labels[$mainId] ?? null)
            ->filter()
            ->mapWithKeys(fn (string $label, $id): array => [(int) $id => $label])
            ->all();
    }

    /**
     * ايّ من الاصناف المعطاة صنف رئيسي له اصناف فرعية.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public static function idsWithSubProducts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Product::query()
            ->whereIn('main_product_id', $ids)
            ->distinct()
            ->pluck('main_product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private static function baseQuery(): Builder
    {
        return Product::query()
            ->select(['id', 'sku', 'barcode', 'base_price', 'parent_id', 'main_product_id', 'category_finish_id', 'weight_per_meter', 'length', 'pricing_type', 'item_type', 'service_kind', 'service_cost_source'])
            ->with(['translations' => fn ($query) => $query
                ->select(['id', 'product_id', 'locale', 'name'])
                ->whereIn('locale', self::searchLocales())]);
    }

    /**
     * Output products that have a cutting recipe from the given source product,
     * keyed by output product id, for a Select's ->options().
     *
     * @return array<int, string>
     */
    public static function cuttingRecipeOutputOptions(mixed $sourceProductId): array
    {
        if (blank($sourceProductId)) {
            return [];
        }

        return ProductCuttingRecipe::query()
            ->where('source_product_id', $sourceProductId)
            ->with(['outputProduct' => fn ($query) => $query
                ->select(['id', 'sku', 'barcode'])
                ->with(['translations' => fn ($translationQuery) => $translationQuery
                    ->select(['id', 'product_id', 'locale', 'name'])
                    ->whereIn('locale', self::searchLocales())])])
            ->get()
            ->filter(fn ($recipe) => $recipe->outputProduct !== null)
            ->mapWithKeys(fn ($recipe) => [
                $recipe->output_product_id => self::labelFor($recipe->outputProduct).' ('.__('yields :count pcs', ['count' => rtrim(rtrim(number_format((float) $recipe->pieces_per_unit, 2), '0'), '.')]).')',
            ])
            ->toArray();
    }

    public static function cuttingRecipePiecesPerUnit(mixed $sourceProductId, mixed $outputProductId): ?string
    {
        if (blank($sourceProductId) || blank($outputProductId)) {
            return null;
        }

        $recipe = ProductCuttingRecipe::query()
            ->where('source_product_id', $sourceProductId)
            ->where('output_product_id', $outputProductId)
            ->first();

        return $recipe ? number_format((float) $recipe->pieces_per_unit, 4, '.', '') : null;
    }

    private static function labelFor(Product $product): string
    {
        return trim(($product->sku ? "{$product->sku} - " : '').$product->name);
    }

    private static function rememberLabel(Product $product): string
    {
        return self::$labelCache[(int) $product->id] = self::labelFor($product);
    }

    /**
     * مرحلة بحث واحدة لنص واحد، ثم ترتيب المرشحين. الرقم والباركود وبداية
     * الاسم في مرحلة الكلمات فقط، فهي ادق المراحل.
     */
    private static function run(SearchQuery $query, MatchMode $mode, int $limit, SearchFilters $filters): SearchResult
    {
        // مرشحون اكثر من المطلوب، ليختار الترتيب افضلهم.
        $pool = max($limit * 3, 60);

        $ids = $mode === MatchMode::Words
            ? self::mergeIds(self::identifierIds($query, $limit), self::namePrefixIds($query, $limit))
            : [];

        $ids = self::mergeIds($ids, self::indexIds($query, $mode, $pool, $filters));
        $ranked = self::rank($query, $ids, $filters);

        return new SearchResult(
            $query,
            array_slice($ranked, 0, $limit),
            hasMore: count($ranked) > $limit,
            approximate: $mode === MatchMode::AnyWord && $ranked !== [],
        );
    }

    /**
     * اجزاء معرّفات الاصناف المطابقة، من الارخص: الرقم والباركود، وبداية الاسم
     * (لصنف لم يُفهرس بعد)، وفهرس البحث. يجمعها apply() بـ UNION ALL في جدول
     * مشتق يحسبه MySQL مرة واحدة، بدل شروط OR تُقيَّم على كل صف.
     *
     * @return array<int, QueryBuilder>
     */
    private static function matchingIdQueries(SearchQuery $searchQuery, MatchMode $mode, bool $withIndex = true): array
    {
        $prefix = config('items.table_prefix');
        $raw = $searchQuery->raw;
        $like = SearchQuery::escapeLike($raw).'%';
        $codes = array_values(array_unique([$raw, $searchQuery->compact()]));

        $parts = [DB::table($prefix.'products')
            ->select('id')
            ->where(fn (QueryBuilder $query) => $query
                ->whereIn('sku', $codes)
                ->orWhereIn('barcode', $codes)
                ->orWhere('sku', 'like', $like)
                ->orWhere('barcode', 'like', $like))];

        if (SearchCapabilities::hasSecondaryBarcodes()) {
            $parts[] = DB::table($prefix.'product_barcodes')
                ->select('product_id')
                ->where(fn (QueryBuilder $query) => $query
                    ->whereIn('barcode', $codes)
                    ->orWhere('barcode', 'like', $like));
        }

        if ($mode === MatchMode::Words) {
            $parts[] = DB::table($prefix.'product_translations')
                ->select('product_id')
                ->whereIn('locale', self::searchLocales())
                ->where('name', 'like', $like);
        }

        if ($withIndex && SearchCapabilities::hasProductIndex()) {
            $index = DB::table(SearchCapabilities::productTable().' as search')->select('search.product_id');
            self::matchIndex($index, 'search.'.SearchCapabilities::DOCUMENT, $searchQuery, $mode);
            $parts[] = $index;
        }

        return $parts;
    }

    /**
     * المرشحون من محرك البحث (Meilisearch: يتحمل الخطأ الاملائي ويرتب بالاقرب)، او null اذا
     * لم يعمل المحرك، فيُبحث في فهرس قاعدة البيانات. «الكلمات»: كل الكلمات مطلوبة؛ «بعض
     * الكلمات»: ما طابق اكثرها؛ «وسط الكلمة» لا يدعمها المحرك فتبقى لقاعدة البيانات.
     *
     * @return list<int>|null
     */
    private static function engineIds(SearchQuery $searchQuery, MatchMode $mode, int $limit, ?SearchFilters $filters = null): ?array
    {
        if ($mode === MatchMode::Contains) {
            return null;
        }

        return SearchIndex::ids(
            Product::class,
            $searchQuery->normalized,
            $limit,
            $filters?->engineFilters() ?? [],
            ['matchingStrategy' => $mode === MatchMode::Words ? 'all' : 'last'],
            swapLayout: false,
        );
    }

    /**
     * مطابقة نص الفهرس. FULLTEXT للكلمات التي يفهرسها، و LIKE لما هو اقصر منها
     * او حين لا يوجد FULLTEXT. يعيد تعبير FULLTEXT اذا استُعمل، ليُرتَّب بدرجته.
     */
    private static function matchIndex(QueryBuilder $query, string $column, SearchQuery $searchQuery, MatchMode $mode): ?string
    {
        $minLength = SearchCapabilities::minTokenSize();
        $useFullText = SearchCapabilities::hasFullText() && $searchQuery->longTerms($minLength) !== [];

        if ($mode === MatchMode::Contains) {
            self::whereLikeAll($query, $column, $searchQuery->likePatterns(anywhere: true));

            return null;
        }

        if ($mode === MatchMode::AnyWord) {
            if ($useFullText) {
                $fullText = $searchQuery->booleanFullText($minLength, requireAll: false);
                $query->whereRaw("MATCH ({$column}) AGAINST (? IN BOOLEAN MODE)", [$fullText]);

                return $fullText;
            }

            $query->where(function (QueryBuilder $query) use ($column, $searchQuery) {
                foreach (array_merge(...$searchQuery->likePatterns()) as $pattern) {
                    $query->orWhere($column, 'like', $pattern);
                }
            });

            return null;
        }

        if (! $useFullText) {
            self::whereLikeAll($query, $column, $searchQuery->likePatterns());

            return null;
        }

        $fullText = $searchQuery->booleanFullText($minLength);
        $query->whereRaw("MATCH ({$column}) AGAINST (? IN BOOLEAN MODE)", [$fullText]);

        // FULLTEXT لا يفهرس الكلمة القصيرة، فتُطابَق بداية كلمة بـ LIKE.
        self::whereLikeAll($query, $column, $searchQuery->likePatterns(terms: $searchQuery->shortTerms($minLength)));

        return $fullText;
    }

    /**
     * كل كلمة لا بد ان تطابق احد بدائلها.
     *
     * @param  array<int, array<int, string>>  $patterns
     */
    private static function whereLikeAll(QueryBuilder $query, string $column, array $patterns): void
    {
        foreach ($patterns as $alternatives) {
            $query->where(function (QueryBuilder $query) use ($column, $alternatives) {
                foreach ($alternatives as $pattern) {
                    $query->orWhere($column, 'like', $pattern);
                }
            });
        }
    }

    /**
     * المرشحون من فهرس البحث، والتصفية داخل الاستعلام (بربط جدول الاصناف).
     *
     * يُرتَّب بدرجة FULLTEXT اذا كان في البحث اكثر من كلمة (فالمطابقات قليلة
     * وفرزها رخيص) او في المطابقة الجزئية (فالدرجة عدد ما طابق). اما الكلمة
     * الواحدة الشائعة فقد تطابق عشرات الآلاف، وفرزها كلها اغلى مما يفيد،
     * فيكفي اول المطابقات ويرتبها SearchScorer.
     *
     * @return array<int, int>
     */
    private static function indexIds(SearchQuery $searchQuery, MatchMode $mode, int $limit, SearchFilters $filters): array
    {
        // المحرك اولاً؛ ان لم يجد شيئاً (او لم يعمل) فالفهرس في قاعدة البيانات كما كان.
        $engineIds = self::engineIds($searchQuery, $mode, $limit, $filters);

        if ($engineIds !== null && $engineIds !== []) {
            return $engineIds;
        }

        if (! SearchCapabilities::hasProductIndex()) {
            return [];
        }

        $column = 'search.'.SearchCapabilities::DOCUMENT;
        $query = DB::table(SearchCapabilities::productTable().' as search')->select('search.product_id');
        $fullText = self::matchIndex($query, $column, $searchQuery, $mode);

        if (! $filters->isEmpty()) {
            $query->join(config('items.table_prefix').'products as products', 'products.id', '=', 'search.product_id');
            $filters->apply($query, 'products');
        }

        if ($fullText !== null && (count($searchQuery->terms) > 1 || $mode === MatchMode::AnyWord)) {
            $query->orderByRaw("MATCH ({$column}) AGAINST (? IN BOOLEAN MODE) DESC", [$fullText]);
        }

        return $query->limit($limit)->pluck('search.product_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * ترتيب المرشحين بالاقرب بعد جلبهم مع التصفية. عند التساوي يبقى ترتيب
     * المراحل (الرقم ثم الاسم ثم درجة FULLTEXT).
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private static function rank(SearchQuery $searchQuery, array $ids, SearchFilters $filters): array
    {
        if ($ids === []) {
            return [];
        }

        $order = array_flip($ids);
        $secondaryBarcodes = self::exactSecondaryBarcodes($searchQuery, $ids);

        $documents = SearchCapabilities::hasProductIndex()
            ? DB::table(SearchCapabilities::productTable())->whereIn('product_id', $ids)->pluck(SearchCapabilities::DOCUMENT, 'product_id')
            : collect();

        $query = Product::query()
            ->select(['id', 'sku', 'barcode', 'is_active'])
            ->with(['translations' => fn ($query) => $query->select(['id', 'product_id', 'locale', 'name'])])
            ->whereIn('id', $ids)
            ->whereNull('parent_id');

        if (! $filters->isEmpty()) {
            $filters->apply($query, $query->getModel()->getTable());
        }

        return $query->get()
            ->map(fn (Product $product): array => [
                'id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'score' => SearchScorer::score(
                    $searchQuery,
                    $product->translations->pluck('name')->all(),
                    $product->sku,
                    // الباركود الاضافي المطابق تماماً يأخذ وزن الباركود الرئيسي.
                    $secondaryBarcodes[(int) $product->id] ?? $product->barcode,
                    $documents->get($product->id),
                    (int) $product->is_active !== 0,
                ),
            ])
            ->sort(fn (array $a, array $b): int => $b['score'] <=> $a['score']
                ?: $order[$a['id']] <=> $order[$b['id']]
                ?: strnatcmp($a['sku'], $b['sku']))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * الباركودات الاضافية المساوية لنص البحث، مفهرسة بالصنف.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private static function exactSecondaryBarcodes(SearchQuery $searchQuery, array $ids): array
    {
        if (! SearchCapabilities::hasSecondaryBarcodes()) {
            return [];
        }

        return DB::table(config('items.table_prefix').'product_barcodes')
            ->whereIn('product_id', $ids)
            ->whereIn('barcode', array_values(array_unique([$searchQuery->raw, $searchQuery->compact()])))
            ->pluck('barcode', 'product_id')
            ->mapWithKeys(fn (string $barcode, $productId): array => [(int) $productId => $barcode])
            ->all();
    }

    /**
     * الاصناف التي يساوي احد باركوداتها الاضافية نص البحث او يبدأ به. المحذوف
     * والمتفرع يُستبعدان عند الترتيب (rank).
     *
     * @param  array<int, string>  $codes
     * @return array<int, int>
     */
    private static function secondaryBarcodeIds(array $codes, ?string $like, int $limit): array
    {
        if (! SearchCapabilities::hasSecondaryBarcodes()) {
            return [];
        }

        return DB::table(config('items.table_prefix').'product_barcodes')
            ->where(fn (QueryBuilder $query) => $query
                ->whereIn('barcode', $codes)
                ->when($like !== null, fn (QueryBuilder $query) => $query->orWhere('barcode', 'like', $like)))
            ->orderBy('barcode')
            ->limit($limit)
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private static function identifierIds(SearchQuery $searchQuery, int $limit): array
    {
        $term = $searchQuery->raw;
        $codes = array_values(array_unique([$term, $searchQuery->compact()]));

        $ids = Product::query()
            ->whereNull('parent_id')
            ->where(function (Builder $query) use ($codes) {
                $query
                    ->whereIn('sku', $codes)
                    ->orWhereIn('barcode', $codes);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (count($ids) >= $limit || mb_strlen($term) < 2) {
            return self::mergeIds($ids, self::secondaryBarcodeIds($codes, null, $limit));
        }

        $like = SearchQuery::escapeLike($term).'%';

        $ids = self::mergeIds($ids, Product::query()
            ->whereNull('parent_id')
            ->where(function (Builder $query) use ($like) {
                $query
                    ->where('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like);
            })
            ->orderByRaw('CASE WHEN sku = ? OR barcode = ? THEN 0 ELSE 1 END', [$term, $term])
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());

        return self::mergeIds($ids, self::secondaryBarcodeIds($codes, $like, $limit));
    }

    /**
     * الاسماء التي تبدأ بنص البحث كما كُتب، من فهرس (locale, name) مباشرة.
     *
     * بلا ORDER BY ولا ربط: الفهرس يعيدها مرتبة بالاسم ويتوقف عند الحد، اما
     * الفرز فيمر على كل ما يبدأ بالكلمة (آلاف الاصناف لكلمة شائعة). المحذوف
     * والمتفرع يُستبعدان عند الترتيب (rank).
     *
     * @return array<int, int>
     */
    private static function namePrefixIds(SearchQuery $searchQuery, int $limit): array
    {
        return DB::table(config('items.table_prefix').'product_translations')
            ->whereIn('locale', self::searchLocales())
            ->where('name', 'like', SearchQuery::escapeLike($searchQuery->raw).'%')
            ->limit($limit)
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, int>  $newIds
     * @return array<int, int>
     */
    private static function mergeIds(array $ids, array $newIds): array
    {
        foreach ($newIds as $id) {
            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<int, string>
     */
    private static function searchLocales(): array
    {
        return array_values(array_unique(array_filter([
            app()->getLocale(),
            'ar',
            'en',
        ])));
    }
}
