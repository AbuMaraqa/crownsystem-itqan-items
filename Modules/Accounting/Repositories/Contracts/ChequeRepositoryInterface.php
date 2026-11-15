<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\Cheque;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface ChequeRepositoryInterface extends RepositoryInterface
{
    /**
     * The cheques register: party (with avatar), accounts, currency and the voucher it came from.
     */
    public function tableQuery(): Builder;

    /**
     * A cheque by id, deleted ones included (the register can show them).
     */
    public function findWithTrashedOrFail(int $id): Cheque;
}
