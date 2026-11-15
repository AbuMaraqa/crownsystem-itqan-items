<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\StockTransfer;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface StockTransferRepositoryInterface extends RepositoryInterface
{
    /**
     * The stock transfers table: both warehouses and creator.
     */
    public function tableQuery(): Builder;

    /**
     * A transfer with its warehouses, lines with both locations, journal entry and poster.
     */
    public function findForPage(int $id): StockTransfer;
}
