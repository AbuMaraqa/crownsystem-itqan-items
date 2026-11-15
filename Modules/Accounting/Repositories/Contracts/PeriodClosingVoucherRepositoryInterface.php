<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface PeriodClosingVoucherRepositoryInterface extends RepositoryInterface
{
    /**
     * The closing vouchers table: fiscal year, journal entry and the creator (with avatar).
     */
    public function tableQuery(): Builder;

    /**
     * Fiscal years that have a closing voucher, for the table filter: id => name.
     *
     * @return Collection<int, string>
     */
    public function fiscalYearOptions(): Collection;
}
