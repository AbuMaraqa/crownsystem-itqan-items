<?php

namespace Modules\Accounting\Services;

use Modules\Accounting\Repositories\Contracts\AdditionalCostTypeRepositoryInterface;

/**
 * بنود التكاليف الاضافية التي تُختار في الطلبية التجميعية.
 */
class AdditionalCostTypeService extends LookupService
{
    public function __construct(AdditionalCostTypeRepositoryInterface $records)
    {
        parent::__construct($records);
    }
}
