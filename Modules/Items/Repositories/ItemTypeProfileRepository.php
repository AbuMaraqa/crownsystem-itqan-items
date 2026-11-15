<?php

namespace Modules\Items\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Repositories\BaseEloquentRepository;
use Modules\Items\Entities\ItemTypeProfile;
use Modules\Items\Repositories\Contracts\ItemTypeProfileRepositoryInterface;

class ItemTypeProfileRepository extends BaseEloquentRepository implements ItemTypeProfileRepositoryInterface
{
    public function __construct(ItemTypeProfile $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with('translations');
    }
}
