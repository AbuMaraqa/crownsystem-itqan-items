<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\SalesOrderType;
use Modules\Accounting\Repositories\Contracts\SalesOrderTypeRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class SalesOrderTypeRepository extends BaseEloquentRepository implements SalesOrderTypeRepositoryInterface
{
    public function __construct(SalesOrderType $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query();
    }
}
