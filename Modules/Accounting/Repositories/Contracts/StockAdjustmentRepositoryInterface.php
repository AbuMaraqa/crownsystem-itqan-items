<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\StockAdjustment;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface StockAdjustmentRepositoryInterface extends RepositoryInterface
{
    /**
     * The stock adjustments table (opening balances, counts, adjustments): warehouse and creator.
     */
    public function tableQuery(): Builder;

    /**
     * An adjustment with its warehouse, lines with their location, journal entry and poster.
     */
    public function findForPage(int $id): StockAdjustment;
}
