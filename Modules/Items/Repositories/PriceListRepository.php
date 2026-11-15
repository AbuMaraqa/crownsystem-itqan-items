<?php

namespace Modules\Items\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\BaseEloquentRepository;
use Modules\Items\Entities\PriceList;
use Modules\Items\Repositories\Contracts\PriceListRepositoryInterface;

class PriceListRepository extends BaseEloquentRepository implements PriceListRepositoryInterface
{
    public function __construct(PriceList $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with('translations')->withCount('prices');
    }
}
