<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\DeliveryCostElement;
use Modules\Accounting\Repositories\Contracts\DeliveryCostElementRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class DeliveryCostElementRepository extends BaseEloquentRepository implements DeliveryCostElementRepositoryInterface
{
    public function __construct(DeliveryCostElement $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query();
    }
}
