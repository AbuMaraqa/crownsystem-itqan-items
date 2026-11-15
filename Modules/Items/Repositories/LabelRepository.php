<?php

namespace Modules\Items\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\BaseEloquentRepository;
use Modules\Items\Entities\Label;
use Modules\Items\Repositories\Contracts\LabelRepositoryInterface;

class LabelRepository extends BaseEloquentRepository implements LabelRepositoryInterface
{
    public function __construct(Label $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with('translations');
    }
}
