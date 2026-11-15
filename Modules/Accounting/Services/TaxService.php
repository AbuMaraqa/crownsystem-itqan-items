<?php

namespace Modules\Accounting\Services;

use Modules\Accounting\Repositories\Contracts\TaxRepositoryInterface;

/**
 * الضرائب التي تُختار في مستندات البيع والشراء (النشطة فقط، عبر Tax::options()).
 */
class TaxService extends LookupService
{
    public function __construct(TaxRepositoryInterface $records)
    {
        parent::__construct($records);
    }
}
