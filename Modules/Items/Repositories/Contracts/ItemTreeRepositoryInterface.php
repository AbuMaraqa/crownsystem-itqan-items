<?php

namespace Modules\Items\Repositories\Contracts;

use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Items\Entities\Category;
use Modules\Items\Entities\Product;
use Modules\Items\Support\Search\SearchFilters;

/**
 * Reads of the items tree page (products.tree): the lazily loaded tree, the table of the chosen
 * node, the search, the quantity window and the move targets. A category with a "twin" (a main
 * item in it numbered like it) is one node with its twin.
 */
interface ItemTreeRepositoryInterface extends RepositoryInterface
{
    /** One batch of items inside a category of the tree, and of rows in the table. */
    public const PAGE = 50;

    /**
     * The root categories, `{id, code, name}`.
     *
     * @return list<array{id: int, code: string|null, name: string}>
     */
    public function roots(): array;

    /**
     * What opens under a category: its sub-categories, then its items (not the pieces of other
     * items; the pieces of its twin instead), at most $limit items.
     *
     * @return array{categories: list<array<string, mixed>>, products: list<array<string, mixed>>, has_more: bool}
     */
    public function children(int $categoryId, int $limit): array;

    /**
     * The pieces under a main item, `{id, sku, name}`.
     *
     * @return list<array{id: int, sku: string|null, name: string|null}>
     */
    public function pieces(int $mainId): array;

    /**
     * The table of the chosen node: the node itself (header row), then its children. A main
     * item ($productId) shows its pieces; a category its sub-categories then its items.
     *
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function nodeRows(?int $categoryId, ?int $productId, int $limit): array;

    /**
     * Search results: matching categories, then items by relevance, a piece under its main item.
     *
     * @return array{rows: list<array<string, mixed>>, has_more: bool, summary: array<string, mixed>}
     */
    public function searchRows(string $search, SearchFilters $filters, int $limit): array;

    /**
     * Stock and limits of an item, and of its pieces when it is a main item.
     *
     * @return list<array<string, mixed>>
     */
    public function quantityRows(int $productId): array;

    /**
     * Destination categories of a move: plain categories only, never a main item's twin.
     *
     * @return list<array{value: int, label: string}>
     */
    public function moveTargets(string $search): array;

    /**
     * The category and its parents, the root first.
     *
     * @return list<int>
     */
    public function categoryPath(int $categoryId): array;

    /**
     * The main item a new item is suggested under: the highlighted row when it is a main item
     * or a piece, else the main item shown, else the shown category's twin when it is by weight.
     */
    public function suggestedMainId(?int $highlightedId, ?int $selectedProductId, ?int $selectedCategoryId): ?int;

    /**
     * The tree parent of a card's item when it has no main item: its category (or the parent of
     * the category it is the twin of), or the chosen category for a new item.
     */
    public function cardParentCategory(?Product $product, ?int $selectedCategoryId): ?Category;
}
