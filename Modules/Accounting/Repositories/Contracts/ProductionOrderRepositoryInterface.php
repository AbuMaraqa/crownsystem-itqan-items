<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\ProductionOrder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface ProductionOrderRepositoryInterface extends RepositoryInterface
{
    /**
     * The production orders table: sales order, client (with avatar) and currency.
     */
    public function tableQuery(): Builder;

    /**
     * An order with everything its page shows: sales order, client, currency and its three cost
     * line lists.
     */
    public function findForPage(int $id): ProductionOrder;
}
