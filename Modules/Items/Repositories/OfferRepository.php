<?php

namespace Modules\Items\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\BaseEloquentRepository;
use Modules\Items\Entities\Offer;
use Modules\Items\Entities\Product;
use Modules\Items\Repositories\Contracts\OfferRepositoryInterface;
use Modules\Items\Support\CategorySearch;
use Modules\Items\Support\ProductSearch;

class OfferRepository extends BaseEloquentRepository implements OfferRepositoryInterface
{
    public function __construct(Offer $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['translations', 'media', 'branch.translations', 'offerable.translations']);
    }

    public function findForForm(int $id): Offer
    {
        /** @var Offer */
        return $this->query()->with(['translations', 'media'])->findOrFail($id);
    }

    public function targetOptions(string $model, array $branchIds, string $search, int $limit = 50): array
    {
        $labels = match ($model) {
            'items_category' => CategorySearch::options($search, $limit),
            'items_product' => ProductSearch::labels($this->productIds($branchIds, $search, $limit)),
            default => [],
        };

        return collect($labels)->map(fn (string $label, int $id) => ['value' => $id, 'label' => $label])->values()->all();
    }

    public function targetLabel(?string $model, mixed $id): ?string
    {
        return match ($model) {
            'items_category' => CategorySearch::label($id),
            'items_product' => ProductSearch::label($id),
            default => null,
        };
    }

    /**
     * Main products sold in the branches, the closest to the search first.
     *
     * @param  list<int>  $branchIds
     * @return list<int>
     */
    private function productIds(array $branchIds, string $search, int $limit): array
    {
        if ($branchIds === []) {
            return [];
        }

        $query = Product::query()
            ->whereNull('parent_id')
            ->whereHas('branches', fn (Builder $branches) => $branches->whereIn(config('items.table_prefix').'branch_product.branch_id', $branchIds));

        if (trim($search) !== '') {
            ProductSearch::apply($query, $search);
            ProductSearch::orderByRelevance($query, $search);
        }

        return $query->orderBy($query->qualifyColumn('id'))->limit($limit)->pluck($query->qualifyColumn('id'))->map(fn ($id) => (int) $id)->all();
    }
}
