<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface ReappraisalElementRepositoryInterface extends RepositoryInterface
{
    /**
     * The query of the admin table, with its eager loads (the table orders it).
     */
    public function tableQuery(): Builder;
}
