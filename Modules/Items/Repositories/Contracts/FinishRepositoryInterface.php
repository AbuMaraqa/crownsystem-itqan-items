<?php

namespace Modules\Items\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface FinishRepositoryInterface extends RepositoryInterface
{
    /**
     * The query of the admin colours table, with its eager loads (the table orders it).
     */
    public function tableQuery(): Builder;
}
