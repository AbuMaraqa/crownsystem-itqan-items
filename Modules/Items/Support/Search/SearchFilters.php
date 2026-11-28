<?php

namespace Modules\Items\Support\Search;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * تصفية نتائج البحث داخل الاستعلام نفسه، لا بعد جلب النتائج، فلا تنقص
 * النتائج حين تكون التصفية ضيقة.
 */
final class SearchFilters
{
    /**
     * @param  string|null  $pricingType  simple (بالكمية) او weight (بالوزن)
     * @param  bool|null  $active  نشط او غير نشط
     * @param  array<int, int>|null  $categoryIds  اصناف هذه التصنيفات، واصناف اصنافها الرئيسية
     * @param  string|null  $itemType  goods (سلعة) او service (خدمة)
     */
    public function __construct(
        public readonly ?string $pricingType = null,
        public readonly ?bool $active = null,
        public readonly ?array $categoryIds = null,
        public readonly ?string $itemType = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->pricingType === null && $this->active === null && $this->categoryIds === null && $this->itemType === null;
    }

    /**
     * التصفية نفسها داخل محرك البحث (حقول مستند الصنف في ItemsSearchIndex)، فلا يملأ
     * المرشحين ما ستستبعده قاعدة البيانات.
     *
     * @return array<string, scalar|list<scalar>>
     */
    public function engineFilters(): array
    {
        return array_filter([
            'pricing_type' => $this->pricingType,
            'item_type' => $this->itemType,
            'is_active' => $this->active,
            'category_ids' => $this->categoryIds === null ? null : ($this->categoryIds ?: [0]),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  string  $table  اسم جدول الاصناف في الاستعلام (او اسمه المستعار)
     */
    public function apply(Builder|EloquentBuilder $query, string $table): void
    {
        if ($this->pricingType !== null) {
            $query->where($table.'.pricing_type', $this->pricingType);
        }

        if ($this->itemType !== null) {
            $query->where($table.'.item_type', $this->itemType);
        }

        if ($this->active !== null) {
            $query->where($table.'.is_active', $this->active ? 1 : 0);
        }

        if ($this->categoryIds !== null) {
            $pivot = config('items.table_prefix').'products_categories';
            $categoryIds = $this->categoryIds ?: [0];

            // الصنف الفرعي يتبع تصنيف صنفه الرئيسي وان لم يُربط به.
            $query->where(fn ($query) => $query
                ->whereExists(fn (Builder $exists) => $exists
                    ->selectRaw('1')
                    ->from($pivot.' as filter_pivot')
                    ->whereColumn('filter_pivot.product_id', $table.'.id')
                    ->whereIn('filter_pivot.category_id', $categoryIds))
                ->orWhereIn($table.'.main_product_id', fn (Builder $mains) => $mains
                    ->select('product_id')
                    ->from($pivot)
                    ->whereIn('category_id', $categoryIds)));
        }
    }
}
