<?php

namespace Modules\Items\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface ItemTypeProfileRepositoryInterface extends RepositoryInterface
{
    /**
     * The admin item types table: each profile with its translations.
     */
    public function tableQuery(): Builder;
}
