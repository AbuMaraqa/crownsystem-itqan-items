<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\Tax;
use Modules\Accounting\Repositories\Contracts\TaxRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class TaxRepository extends BaseEloquentRepository implements TaxRepositoryInterface
{
    public function __construct(Tax $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query();
    }
}
