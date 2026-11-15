<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface CustomerGroupRepositoryInterface extends RepositoryInterface
{
    /**
     * The query of the admin table, with its eager loads (the table orders it).
     */
    public function tableQuery(): Builder;

    /**
     * Customers (role Customer) whose name or email matches the term, for the customers
     * field of a group: the first 50 by name.
     *
     * @return list<array{value: int, label: string}>
     */
    public function customerOptions(string $term = ''): array;
}
