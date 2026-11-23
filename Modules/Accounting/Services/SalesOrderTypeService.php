<?php

namespace Modules\Accounting\Services;

use Modules\Accounting\Repositories\Contracts\SalesOrderTypeRepositoryInterface;

/**
 * أنواع طلبيات البيع ومهلها (دليل أنواع الطلبيات).
 */
class SalesOrderTypeService extends LookupService
{
    public function __construct(SalesOrderTypeRepositoryInterface $records)
    {
        parent::__construct($records);
    }
}
