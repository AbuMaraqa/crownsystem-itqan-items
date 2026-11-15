<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface FiscalYearRepositoryInterface extends RepositoryInterface
{
    /**
     * The fiscal years table, with how many monthly periods each year has.
     */
    public function tableQuery(): Builder;

    /**
     * The monthly periods of one fiscal year.
     */
    public function periodsQuery(int $fiscalYearId): Builder;

    public function findPeriodOrFail(int $id): AccountingPeriod;
}
