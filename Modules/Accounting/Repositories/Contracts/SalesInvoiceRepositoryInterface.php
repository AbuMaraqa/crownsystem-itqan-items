<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface SalesInvoiceRepositoryInterface extends RepositoryInterface
{
    /**
     * The sales invoices table: order, client (with avatar), warehouse, currency and tax.
     */
    public function tableQuery(): Builder;

    /**
     * An invoice with everything its page shows: lines with their products, pieces and providers,
     * journal entry, order (with its customer materials), client, warehouse, currency and tax.
     */
    public function findForPage(int $id): SalesInvoice;
}
