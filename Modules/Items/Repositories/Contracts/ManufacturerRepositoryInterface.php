<?php

namespace Modules\Items\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface ManufacturerRepositoryInterface extends RepositoryInterface
{
    /**
     * The query of the admin manufacturers table, with its eager loads (the table orders it).
     */
    public function tableQuery(): Builder;
}
