<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\Warehouse;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface WarehouseRepositoryInterface extends RepositoryInterface
{
    /**
     * جدول المستودعات: الترجمات والفرع وحساب المخزون.
     */
    public function tableQuery(): Builder;

    /**
     * مستودع مع ترجماته، لنموذج التعديل.
     */
    public function findForForm(int $id): Warehouse;
}
