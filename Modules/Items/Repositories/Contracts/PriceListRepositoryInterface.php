<?php

namespace Modules\Items\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface PriceListRepositoryInterface extends RepositoryInterface
{
    /**
     * The admin price lists table: each list with its translations and how many prices it holds.
     */
    public function tableQuery(): Builder;
}
