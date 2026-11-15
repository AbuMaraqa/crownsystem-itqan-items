<?php

namespace Modules\Core\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Entities\User;

interface UserRepositoryInterface extends RepositoryInterface
{
    /**
     * جدول المستخدمين: كل مستخدم مع ادواره وفرعه.
     */
    public function tableQuery(): Builder;

    public function findWithRoles(int $id): User;

    public function countWithRole(string $role): int;
}
