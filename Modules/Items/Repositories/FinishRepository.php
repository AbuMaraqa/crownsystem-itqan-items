<?php

namespace Modules\Items\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\BaseEloquentRepository;
use Modules\Items\Entities\Finish;
use Modules\Items\Repositories\Contracts\FinishRepositoryInterface;

class FinishRepository extends BaseEloquentRepository implements FinishRepositoryInterface
{
    public function __construct(Finish $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with('attributeValue')->withCount('categoryFinishes');
    }
}
