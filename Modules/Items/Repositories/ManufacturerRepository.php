<?php

namespace Modules\Items\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\BaseEloquentRepository;
use Modules\Items\Entities\Manufacturer;
use Modules\Items\Repositories\Contracts\ManufacturerRepositoryInterface;

class ManufacturerRepository extends BaseEloquentRepository implements ManufacturerRepositoryInterface
{
    public function __construct(Manufacturer $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['translations', 'media']);
    }
}
