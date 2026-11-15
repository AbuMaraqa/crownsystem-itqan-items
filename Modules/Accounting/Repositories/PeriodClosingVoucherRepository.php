<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Accounting\Entities\FiscalYear;
use Modules\Accounting\Entities\PeriodClosingVoucher;
use Modules\Accounting\Repositories\Contracts\PeriodClosingVoucherRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class PeriodClosingVoucherRepository extends BaseEloquentRepository implements PeriodClosingVoucherRepositoryInterface
{
    public function __construct(PeriodClosingVoucher $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['fiscalYear', 'journalEntry', 'createdBy.media']);
    }

    public function fiscalYearOptions(): Collection
    {
        return FiscalYear::query()
            ->whereIn('id', $this->query()->select('fiscal_year_id'))
            ->orderByDesc('starts_at')
            ->pluck('name', 'id');
    }
}
