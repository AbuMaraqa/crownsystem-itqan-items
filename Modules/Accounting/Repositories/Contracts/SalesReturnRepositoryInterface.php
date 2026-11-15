<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface SalesReturnRepositoryInterface extends RepositoryInterface
{
    /**
     * The sales returns table: invoice, client (with avatar), warehouse, currency and tax.
     */
    public function tableQuery(): Builder;

    /**
     * A return with everything its page shows: lines with their pieces, journal entry, invoice,
     * client, warehouse, currency and tax.
     */
    public function findForPage(int $id): SalesReturn;
}
