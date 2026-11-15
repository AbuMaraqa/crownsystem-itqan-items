<?php

namespace Modules\Items\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Items\Entities\Brand;

interface BrandRepositoryInterface extends RepositoryInterface
{
    /**
     * The query of the admin brands table, with its eager loads (the table orders it).
     */
    public function tableQuery(): Builder;

    /**
     * Whether an item (deleted ones too) uses the brand.
     */
    public function isUsed(Brand $brand): bool;
}
