<?php

namespace Modules\Core\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Entities\User;
use Modules\Core\Repositories\Contracts\UserRepositoryInterface;

class UserRepository extends BaseEloquentRepository implements UserRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['roles', 'branch.translations', 'media']);
    }

    public function findWithRoles(int $id): User
    {
        /** @var User */
        return $this->query()->with(['roles', 'branch.translations'])->findOrFail($id);
    }

    public function countWithRole(string $role): int
    {
        return $this->query()->whereHas('roles', fn (Builder $query) => $query->where('name', $role))->count();
    }
}
