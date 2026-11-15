<?php

namespace Modules\Items\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Items\Entities\Product;

interface ProductRepositoryInterface extends RepositoryInterface
{
    /**
     * The items list: main rows only (no variations, no sub-items), with what a row shows:
     * names, categories, image and the sub-items listed inside their main item.
     */
    public function tableQuery(): Builder;

    /**
     * Narrow a query to the items matching a search (ProductSearch), or whose sub-item matches,
     * so a main item shows when its sub-item's name, code or barcode is searched.
     */
    public function searchWithSubProducts(Builder $query, string $search): Builder;

    /**
     * Narrow a query to the items of a category, and the main items one of whose sub-items is in it.
     */
    public function inCategory(Builder $query, int $categoryId): Builder;

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
     * The sub-items of a main item: name, code, barcode, length and weight per metre.
     *
     * @return list<array<string, mixed>>
     */
    public function subProductRows(Product $product): array;

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
