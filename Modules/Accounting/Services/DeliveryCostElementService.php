<?php

namespace Modules\Accounting\Services;

use Modules\Accounting\Repositories\Contracts\DeliveryCostElementRepositoryInterface;

/**
 * عناصر تقدير تكلفة التوصيل في الطلبية التجميعية.
 */
class DeliveryCostElementService extends LookupService
{
    public function __construct(DeliveryCostElementRepositoryInterface $records)
    {
        parent::__construct($records);
    }
}
