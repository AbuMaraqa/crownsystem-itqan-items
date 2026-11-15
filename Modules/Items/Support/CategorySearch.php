<?php

namespace Modules\Items\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Items\Entities\Category;
use Modules\Items\Enums\CategoryStatus;
use Modules\Items\Support\Search\SearchCapabilities;
use Modules\Items\Support\Search\SearchFilters;
use Modules\Items\Support\Search\SearchQuery;

/**
 * Category lookups for "add a whole category of items" pickers: searchable options,
 * labels for already selected ids, descendant resolution and the products inside them.
 */
class CategorySearch
{
    /**
     * Guards against a cycle in parent_id, and keeps the descendant walk bounded.
     */
    private const MAX_DEPTH = 10;

    /**
     * Active categories for a Select's ->options() / ->getSearchResultsUsing().
     *
     * @return array<int, string>
     */
    public static function options(string $search = '', int $limit = 50): array
    {
        $term = trim(preg_replace('/\s+/u', ' ', $search) ?? '');
        $limit = max($limit, 1);

        $ids = self::matchingIds($term, $limit);

        if ($ids === []) {
            return [];
        }

        $labels = self::labels($ids);

        return collect($ids)
            ->filter(fn (int $id): bool => isset($labels[$id]))
            ->mapWithKeys(fn (int $id): array => [$id => $labels[$id]])
            ->toArray();
    }

    /**
     * Labels keyed by category id, prefixed with the parent name when there is one
     * so sibling categories sharing a name stay distinguishable.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    public static function labels(array $ids): array
    {
        $ids = self::normalizeIds($ids);

        if ($ids === []) {
            return [];
        }

        return Category::query()
            ->whereIn('id', $ids)
            ->with(['translations', 'parent.translations'])
            ->get()
            ->mapWithKeys(fn (Category $category): array => [
                (int) $category->id => trim(($category->parent?->name ? $category->parent->name.' / ' : '').$category->name),
            ])
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
     * The given categories plus every active category nested underneath them.
     *
     * @param  array<int, mixed>  $categoryIds
     * @return array<int, int>
     */
    public static function withDescendantIds(array $categoryIds): array
    {
        $ids = self::normalizeIds($categoryIds);

        if ($ids === []) {
            return [];
        }

        $all = $ids;
        $frontier = $ids;

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; $depth++) {
            $children = Category::query()
                ->whereIn('parent_id', $frontier)
                ->where('status', CategoryStatus::ACTIVE)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $frontier = array_values(array_diff($children, $all));
            $all = array_merge($all, $frontier);
        }

        return array_values(array_unique($all));
    }

    /**
     * Ids of the products attached to the given categories, name-ordered.
     * Variants (products with a parent) are excluded, matching the item search.
     *
     * @param  array<int, mixed>  $categoryIds
     * @return array<int, int>
     */
    public static function productIds(array $categoryIds, int $limit = 200): array
    {
        $categoryIds = self::normalizeIds($categoryIds);

        if ($categoryIds === []) {
            return [];
        }

        $productsTable = config('items.table_prefix').'products';
        $pivotTable = config('items.table_prefix').'products_categories';
        $translationsTable = config('items.table_prefix').'product_translations';

        return DB::table($pivotTable.' as pivot')
            ->join($productsTable.' as products', 'products.id', '=', 'pivot.product_id')
            ->leftJoin($translationsTable.' as translations', function ($join) {
                $join->on('translations.product_id', '=', 'products.id')
                    ->where('translations.locale', '=', app()->getLocale());
            })
            ->whereIn('pivot.category_id', $categoryIds)
            ->whereNull('products.parent_id')
            ->whereNull('products.deleted_at')
            ->groupBy('products.id')
            ->orderByRaw('MIN(translations.name)')
            ->limit(max($limit, 1))
            ->pluck('products.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * اصناف تصنيف معيّن (وفروعه) لقائمة بحث، مع امكانية تصفيتها بنص البحث.
     * تُستخدم داخل صفوف المجموعة في جدول الاصناف.
     *
     * @return array<int, string>
     */
    public static function productOptions(mixed $categoryId, string $search = '', int $limit = 25): array
    {
        $categoryIds = self::withDescendantIds([$categoryId]);

        if ($categoryIds === []) {
            return [];
        }

        $term = trim(preg_replace('/\s+/u', ' ', $search) ?? '');

        // بلا نص بحث نعرض اوائل اصناف المجموعة مباشرة، ليتصفحها المستخدم.
        $productIds = $term === ''
            ? self::productIds($categoryIds, $limit)
            : ProductSearch::find($term, $limit, new SearchFilters(categoryIds: $categoryIds))->ids;

        return ProductSearch::labels(array_slice($productIds, 0, $limit));
    }

    /**
     * @return array<int, int>
     */
    private static function matchingIds(string $term, int $limit): array
    {
        $query = Category::query()->where('status', CategoryStatus::ACTIVE);

        if ($term !== '') {
            self::constrain($query, SearchQuery::from($term));
        }

        return $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * التصنيفات المطابقة للبحث (كل الكلمات بأي ترتيب، او رقم التصنيف)،
     * مرتبةً بالرقم كما في الشجرة.
     *
     * @return Collection<int, Category>
     */
    public static function search(SearchQuery $searchQuery, int $limit = 10): Collection
    {
        if ($searchQuery->isEmpty()) {
            return new Collection;
        }

        return self::constrain(Category::query()->with('translations'), $searchQuery)
            ->orderByRaw('CASE WHEN code = ? THEN 0 WHEN code LIKE ? THEN 1 ELSE 2 END', [$searchQuery->raw, SearchQuery::escapeLike($searchQuery->raw).'%'])
            ->orderBy('code')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * مطابقة التصنيف: رقمه، او كل كلمات البحث في فهرسه (الاسم بكل لغاته موحَّداً)،
     * او (لتصنيف لم يُفهرس بعد) اسمه.
     */
    private static function constrain(Builder $query, SearchQuery $searchQuery): Builder
    {
        $like = '%'.SearchQuery::escapeLike($searchQuery->raw).'%';

        return $query->where(function (Builder $query) use ($searchQuery, $like) {
            $query
                ->where('code', 'like', SearchQuery::escapeLike($searchQuery->raw).'%')
                ->orWhereHas('translations', fn (Builder $translations) => $translations->where('name', 'like', $like));

            if (SearchCapabilities::hasCategoryIndex()) {
                $query->orWhere(function (Builder $index) use ($searchQuery) {
                    foreach ($searchQuery->likePatterns(anywhere: true) as $alternatives) {
                        $index->where(function (Builder $index) use ($alternatives) {
                            foreach ($alternatives as $pattern) {
                                $index->orWhere(SearchCapabilities::CATEGORY_COLUMN, 'like', $pattern);
                            }
                        });
                    }
                });
            }
        });
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private static function normalizeIds(array $ids): array
    {
        return collect($ids)
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
