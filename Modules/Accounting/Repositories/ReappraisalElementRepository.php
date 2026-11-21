<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\ReappraisalElement;
use Modules\Accounting\Repositories\Contracts\ReappraisalElementRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class ReappraisalElementRepository extends BaseEloquentRepository implements ReappraisalElementRepositoryInterface
{
    public function __construct(ReappraisalElement $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query();
    }
}
