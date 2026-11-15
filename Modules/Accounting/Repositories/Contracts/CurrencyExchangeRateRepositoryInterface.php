<?php

namespace Modules\Accounting\Repositories\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface CurrencyExchangeRateRepositoryInterface extends RepositoryInterface
{
    /**
     * The query of the admin table, with its eager loads (the table orders it).
     */
    public function tableQuery(): Builder;

    /**
     * A table sort by the code of the currency in `$foreignKey`, for `AllowedSort::callback()`.
     *
     * @return Closure(Builder, bool): void
     */
    public function currencyCodeSort(string $foreignKey): Closure;
}
