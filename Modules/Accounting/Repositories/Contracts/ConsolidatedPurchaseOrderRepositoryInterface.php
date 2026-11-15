<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface ConsolidatedPurchaseOrderRepositoryInterface extends RepositoryInterface
{
    /**
     * The consolidated purchase orders table: supplier (with avatar), warehouse and currency.
     */
    public function tableQuery(): Builder;

    /**
     * An order with its header relations (supplier, currency, creator, follower).
     */
    public function findForPage(int $id): ConsolidatedPurchaseOrder;
}
