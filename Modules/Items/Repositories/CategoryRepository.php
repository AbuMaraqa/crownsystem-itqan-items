<?php

namespace Modules\Items\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Repositories\BaseEloquentRepository;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\CategoryStatus;
use Modules\Items\Repositories\Contracts\CategoryRepositoryInterface;

class CategoryRepository extends BaseEloquentRepository implements CategoryRepositoryInterface
{
    public function __construct(Category $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['media', 'translations', 'parent.translations']);
    }

    public function parentTree(?Category $except = null): array
    {
        $excluded = $except ? $this->selfAndDescendantIds($except) : [];

        $rows = $this->query()
            ->with('translations')
            ->translated()
            ->where(fn (Builder $query) => $query
                ->where('status', CategoryStatus::ACTIVE)
                ->when($except?->parent_id, fn (Builder $query, $parentId) => $query->orWhere($query->qualifyColumn('id'), $parentId)))
            ->when($excluded !== [], fn (Builder $query) => $query->whereKeyNot($excluded))
            ->catalogOrder()
            ->get();

        // تصنيف ابوه ليس في القائمة (غير نشط) يصعد الى الجذر.
        $ids = $rows->pluck('id')->flip();
        $byParent = $rows->groupBy(fn (Category $category) => $ids->has($category->parent_id) ? $category->parent_id : 0);

        $nodes = function (int $parent) use (&$nodes, $byParent): array {
            return $byParent->get($parent, collect())
                ->map(fn (Category $category) => [
                    'id' => (int) $category->id,
                    'label' => (string) $category->name,
                    'children' => $nodes((int) $category->id),
                ])
                ->values()
                ->all();
        };

        return $nodes(0);
    }

    public function selfAndDescendantIds(Category $category): array
    {
        $ids = [(int) $category->getKey()];
        $level = $ids;

        // استعلام لكل مستوى؛ تخطي ما سبقت رؤيته يوقف اي حلقة في parent_id.
        while ($level !== []) {
            $level = $this->query()->whereIn('parent_id', $level)->whereKeyNot($ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    public function parentOptions(): array
    {
        $table = $this->model->getTable();

        return $this->query()
            ->with('translations')
            ->whereIn('id', fn ($query) => $query->select('parent_id')->from($table)->whereNotNull('parent_id')->whereNull('deleted_at'))
            ->catalogOrder()
            ->get()
            ->mapWithKeys(fn (Category $category) => [(int) $category->id => (string) $category->name])
            ->all();
    }

    /* ---------- شجرة التصنيفات (categories.tree) ---------- */

    public function treeNodes(?int $parentId, bool $showInactive): array
    {
        $categories = $this->treeQuery($showInactive)->where('parent_id', $parentId)->get();
        $counts = $this->productCounts($categories->modelKeys());

        return $categories
            ->map(fn (Category $category): array => $this->treeNode($category, [], $counts))
            ->values()
            ->all();
    }

    /**
     * الشجرة كاملة عند البحث، باستعلام واحد ثم بناؤها في الذاكرة: المطابقة واجدادها.
     */
    public function searchTree(string $search, bool $showInactive): array
    {
        $categories = $this->treeQuery($showInactive)->get();
        $counts = $this->productCounts($categories->modelKeys());
        $needle = mb_strtolower(trim($search));
        $parents = $categories->pluck('parent_id', 'id');
        $keep = [];

        foreach ($categories as $category) {
            if (! str_contains(mb_strtolower((string) $category->name), $needle)
                && ! str_contains(mb_strtolower((string) $category->slug), $needle)) {
                continue;
            }

            $keep[$category->id] = true;
            $parentId = $parents[$category->id] ?? null;

            // ابقاء سلسلة الاجداد حتى تبقى العقدة ظاهرة داخل الشجرة.
            while ($parentId !== null && ! isset($keep[$parentId])) {
                $keep[$parentId] = true;
                $parentId = $parents[$parentId] ?? null;
            }
        }

        $branch = function (?int $parentId) use (&$branch, $categories, $keep, $counts): array {
            return $categories
                ->where('parent_id', $parentId)
                ->filter(fn (Category $category): bool => isset($keep[$category->id]))
                ->map(fn (Category $category): array => $this->treeNode($category, $branch((int) $category->id), $counts))
                ->values()
                ->all();
        };

        return $branch(null);
    }

    public function treeProducts(int $categoryId, int $limit): array
    {
        $prefix = config('items.table_prefix');

        return DB::table($prefix.'products_categories as pivot')
            ->join($prefix.'products as products', 'products.id', '=', 'pivot.product_id')
            ->leftJoin($prefix.'product_translations as translations', function ($join): void {
                $join->on('translations.product_id', '=', 'products.id')
                    ->where('translations.locale', '=', app()->getLocale());
            })
            ->where('pivot.category_id', $categoryId)
            ->whereNull('products.deleted_at')
            ->orderBy('products.sku') // ترتيب بنية الاصناف (Product::scopeCatalogOrder)
            ->orderBy('products.id')
            ->limit($limit)
            ->get(['products.id', 'products.sku', 'products.barcode', 'translations.name'])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => $row->name ?: $row->sku,
                'sku' => $row->sku,
                'barcode' => $row->barcode,
            ])
            ->all();
    }

    public function productsQuery(Category $category): Builder
    {
        // من Product مباشرة: جدول الربط فيه عمود id يتعارض مع products.id عند الـ join.
        return Product::query()
            ->with(['translations', 'media', 'categories.translations', 'parent.translations'])
            ->whereHas('categories', fn (Builder $query) => $query->whereKey($category->id));
    }

    private function treeQuery(bool $showInactive): Builder
    {
        return $this->query()
            ->with(['translations', 'media'])
            // allChildren_count يكفي لمعرفة وجود فروع دون جلبها. عدد الأصناف من productCounts().
            ->withCount('allChildren')
            ->when(! $showInactive, fn (Builder $query) => $query->where('status', CategoryStatus::ACTIVE))
            ->catalogOrder();
    }

    /**
     * عدد الأصناف غير المحذوفة في كل تصنيف: كل روابط التصنيف ناقص روابطه بأصناف محذوفة.
     * الروابط تُعدّ من فهرس جدول الربط (category_id, product_id) وحده، وروابط المحذوف من الأصناف
     * المحذوفة (وهي قليلة)، بدل فحص كل رابط من مئات الآلاف أن صنفه غير محذوف.
     *
     * @param  list<int>  $categoryIds
     * @return array<int, int>
     */
    private function productCounts(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $prefix = config('items.table_prefix');

        // One query, two index-only parts summed: every link (+1), and the links to deleted products (-1),
        // found from the few deleted products. Testing each link against the deleted list instead took
        // about 1.2 s on 300k links; this takes about 0.25 s.
        $links = DB::table($prefix.'products_categories')
            ->whereIn('category_id', $categoryIds)
            ->groupBy('category_id')
            ->selectRaw('category_id, count(*) as aggregate');

        $deletedLinks = DB::table($prefix.'products_categories as links')
            ->join($prefix.'products as products', 'products.id', '=', 'links.product_id')
            ->whereNotNull('products.deleted_at')
            ->whereIn('links.category_id', $categoryIds)
            ->groupBy('links.category_id')
            ->selectRaw('links.category_id, -count(*) as aggregate');

        return DB::query()
            ->fromSub($links->unionAll($deletedLinks), 'counts')
            ->groupBy('category_id')
            ->selectRaw('category_id, sum(aggregate) as aggregate')
            ->pluck('aggregate', 'category_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @param  array<int, int>  $counts  productCounts()
     * @return array<string, mixed>
     */
    private function treeNode(Category $category, array $children, array $counts): array
    {
        return [
            'id' => (int) $category->id,
            'name' => (string) ($category->name ?: $category->slug),
            'slug' => $category->slug,
            'image' => $category->getFirstMediaUrl('category') ?: null,
            'is_active' => $category->status === CategoryStatus::ACTIVE,
            'products_count' => $counts[$category->id] ?? 0,
            'has_children' => $category->all_children_count > 0,
            'children' => $children,
        ];
    }
}
