<?php

namespace Modules\Items\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Items\Entities\Product;

interface ProductRepositoryInterface extends RepositoryInterface
{
    /**
     * The items list: items without their variations, with what a row shows: names, categories,
     * image and the item's colours (its variations that have a colour).
     */
    public function tableQuery(): Builder;

    /**
     * Narrow a query to the items matching a search (ProductSearch).
     */
    public function search(Builder $query, string $search): Builder;

    /**
     * Narrow a query to the items of a category.
     */
    public function inCategory(Builder $query, int $categoryId): Builder;

    /**
     * Narrow a query to the items in a colour: one of their variations has it, or the item itself.
     */
    public function withFinish(Builder $query, int $finishId): Builder;

    /**
     * An item with what its edit form shows.
     */
    public function findForEdit(int $id): Product;

    /**
     * An item and its variations (barcode labels, branch pricings).
     */
    public function withVariations(int $id): Product;

    /**
     * The lists the product form chooses from; `$product` keeps its own inactive item type.
     *
     * @return array<string, mixed>
     */
    public function formOptions(?Product $product = null): array;

    /**
     * Items a form picker offers: `main` (weight main items), `goods` (accessories) or `any`
     * (cutting recipe outputs), without `$exclude`.
     *
     * @return list<array{value: int, label: string, length: string|null}>
     */
    public function lookup(string $type, string $search, ?int $exclude = null): array;

    /**
     * The items this item is cut from, with the pieces each one yields.
     *
     * @return list<array{label: string, yield: string}>
     */
    public function madeFrom(Product $product): array;

    /**
     * Saved branch prices of these items, keyed `product_id:branch_id`.
     *
     * @param  iterable<int>  $productIds
     */
    public function branchPrices(iterable $productIds): Collection;
}
