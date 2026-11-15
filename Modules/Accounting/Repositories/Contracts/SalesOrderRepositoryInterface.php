<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface SalesOrderRepositoryInterface extends RepositoryInterface
{
    /**
     * The sales orders table: quotation, client (with avatar), warehouse, currency and invoices.
     */
    public function tableQuery(): Builder;

    /**
     * An order with everything its page shows: goods and service lines with their products, pieces
     * and providers, customer materials, quotation, invoices, client, warehouse, currency and tax.
     */
    public function findForPage(int $id): SalesOrder;
}
