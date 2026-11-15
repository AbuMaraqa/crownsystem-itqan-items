<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\AccountingPeriod;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Repositories\Contracts\FiscalYearRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class FiscalYearRepository extends BaseEloquentRepository implements FiscalYearRepositoryInterface
{
    public function __construct(FiscalYear $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->withCount('periods');
    }

    public function periodsQuery(int $fiscalYearId): Builder
    {
        return AccountingPeriod::query()->where('fiscal_year_id', $fiscalYearId);
    }

    public function findPeriodOrFail(int $id): AccountingPeriod
    {
        /** @var AccountingPeriod */
        return AccountingPeriod::query()->findOrFail($id);
    }
}
