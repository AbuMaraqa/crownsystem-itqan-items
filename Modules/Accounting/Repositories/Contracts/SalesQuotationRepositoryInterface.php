<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\SalesQuotation;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface SalesQuotationRepositoryInterface extends RepositoryInterface
{
    /**
     * The sales quotations table: client (with avatar), warehouse, currency and sales orders.
     */
    public function tableQuery(): Builder;

    /**
     * A quotation with everything its page shows: lines with their products and pieces, client,
     * warehouse, currency, tax and sales orders.
     */
    public function findForPage(int $id): SalesQuotation;
}
