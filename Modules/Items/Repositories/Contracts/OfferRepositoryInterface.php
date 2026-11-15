<?php

namespace Modules\Items\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Items\Entities\Offer;

interface OfferRepositoryInterface extends RepositoryInterface
{
    /**
     * The admin offers table with what its rows show: image, branch and target names.
     */
    public function tableQuery(): Builder;

    /**
     * An offer with what its form shows: translations and image.
     */
    public function findForForm(int $id): Offer;

    /**
     * What an offer can target, matching a search, for the form's picker: categories, or main
     * products (no variations) sold in one of the given branches (none while no branch is chosen).
     *
     * @param  'items_category'|'items_product'|string  $model
     * @param  list<int>  $branchIds
     * @return list<array{value: int, label: string}>
     */
    public function targetOptions(string $model, array $branchIds, string $search, int $limit = 50): array;

    /**
     * The picker's label of a saved target, or null when it is gone.
     */
    public function targetLabel(?string $model, mixed $id): ?string;
}
