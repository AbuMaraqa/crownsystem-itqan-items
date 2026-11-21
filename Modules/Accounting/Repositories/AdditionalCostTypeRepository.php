<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\AdditionalCostType;
use Modules\Accounting\Repositories\Contracts\AdditionalCostTypeRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class AdditionalCostTypeRepository extends BaseEloquentRepository implements AdditionalCostTypeRepositoryInterface
{
    public function __construct(AdditionalCostType $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query();
    }
}
