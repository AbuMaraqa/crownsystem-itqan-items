<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Repositories\Contracts\SupplierRepositoryInterface;
use Modules\Core\Entities\User;
use Modules\Core\Repositories\BaseEloquentRepository;

class SupplierRepository extends BaseEloquentRepository implements SupplierRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->supplierRole()->with('media');
    }

    public function findSupplier(int $id): User
    {
        /** @var User */
        return $this->query()->supplierRole()->findOrFail($id);
    }
}
