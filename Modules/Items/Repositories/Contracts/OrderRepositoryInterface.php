<?php

namespace Modules\Items\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Items\Entities\Order;

interface OrderRepositoryInterface extends RepositoryInterface
{
    /**
     * The admin orders table: each order with its customer and its lines.
     */
    public function tableQuery(): Builder;

    /**
     * An order with what its form needs: customer, branch, lines and their products.
     */
    public function forForm(int $id): Order;

    /**
     * Options of the order form's searchable fields: `customers`, `products`, `cities`, `districts`,
     * each `[{value, label}]`, at most 50 (25 products).
     *
     * @return list<array{value: int, label: string}>
     */
    public function lookup(string $type, string $search): array;

    /**
     * Labels of saved choices, so the form shows them before any search.
     *
     * @param  list<int>  $ids
     * @return list<array{value: int, label: string}>
     */
    public function labels(string $type, array $ids): array;
}
