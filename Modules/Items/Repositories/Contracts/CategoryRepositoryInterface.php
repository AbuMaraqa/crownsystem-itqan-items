<?php

namespace Modules\Items\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Items\Entities\Category;

interface CategoryRepositoryInterface extends RepositoryInterface
{
    /**
     * The admin categories table: each category with its image, translations and parent.
     */
    public function tableQuery(): Builder;

    /**
     * The tree behind the parent category choice: active categories that have a translation, as
     * nested `{id, label, children}`. Without $except and its children, which cannot be its parent;
     * its current parent stays in the tree even when inactive, so the form still shows it.
     *
     * @return list<array{id: int, label: string, children: array<int, mixed>}>
     */
    public function parentTree(?Category $except = null): array;

    /**
     * The category's id and the ids of all its child categories, at any depth and in any status.
     *
     * @return list<int>
     */
    public function selfAndDescendantIds(Category $category): array;

    /**
     * Categories that have children, id => name, for the table's parent filter.
     *
     * @return array<int, string>
     */
    public function parentOptions(): array;

    /**
     * Nodes of the categories tree page (categories.tree): the roots, or the children of
     * $parentId, each `{id, name, slug, image, is_active, products_count, has_children, children: []}`.
     *
     * @return list<array<string, mixed>>
     */
    public function treeNodes(?int $parentId, bool $showInactive): array;

    /**
     * The whole tree narrowed to the categories whose name or slug contains $search, with their
     * ancestors so each match stays in place.
     *
     * @return list<array<string, mixed>>
     */
    public function searchTree(string $search, bool $showInactive): array;

    /**
     * The first $limit items linked to a category, by name: `{id, name, sku, barcode}`.
     *
     * @return list<array{id: int, name: string|null, sku: string|null, barcode: string|null}>
     */
    public function treeProducts(int $categoryId, int $limit): array;

    /**
     * The items linked to a category, for the category's items table.
     */
    public function productsQuery(Category $category): Builder;
}
