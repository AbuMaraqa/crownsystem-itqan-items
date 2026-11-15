<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Repositories\Contracts\WarehouseRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class WarehouseRepository extends BaseEloquentRepository implements WarehouseRepositoryInterface
{
    public function __construct(Warehouse $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['translations', 'branch.translations', 'inventoryAccount.translations']);
    }

    public function findForForm(int $id): Warehouse
    {
        /** @var Warehouse */
        return $this->query()->with(['translations', 'branch.translations'])->findOrFail($id);
    }
}
