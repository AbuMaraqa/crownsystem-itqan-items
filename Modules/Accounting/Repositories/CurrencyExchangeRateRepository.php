<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\CurrencyExchangeRate;
use Modules\Accounting\Repositories\Contracts\CurrencyExchangeRateRepositoryInterface;
use Modules\Core\Entities\Currency;
use Modules\Core\Repositories\BaseEloquentRepository;

class CurrencyExchangeRateRepository extends BaseEloquentRepository implements CurrencyExchangeRateRepositoryInterface
{
    public function __construct(CurrencyExchangeRate $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['baseCurrency', 'currency']);
    }

    public function currencyCodeSort(string $foreignKey): \Closure
    {
        return function (Builder $query, bool $descending) use ($foreignKey): void {
            $currency = new Currency;

            $query->orderBy(
                Currency::query()
                    ->select('code')
                    ->whereColumn($currency->getQualifiedKeyName(), $query->getModel()->qualifyColumn($foreignKey))
                    ->limit(1),
                $descending ? 'desc' : 'asc',
            );
        };
    }
}
