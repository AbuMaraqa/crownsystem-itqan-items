<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Entities\User;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

/**
 * الموردون: مستخدمون لهم دور Supplier.
 */
interface SupplierRepositoryInterface extends RepositoryInterface
{
    /**
     * جدول الموردين مع صورهم.
     */
    public function tableQuery(): Builder;

    /**
     * مورد واحد؛ مستخدم ليس مورداً لا يُفتح من صفحات الموردين.
     */
    public function findSupplier(int $id): User;
}
