<?php

namespace Modules\Accounting\Services;

use Modules\Accounting\Repositories\Contracts\ReappraisalElementRepositoryInterface;

/**
 * بنود اعادة التخمين. الحذف ناعم: اعادات التخمين المحفوظة تبقى تعرض اسم البند.
 */
class ReappraisalElementService extends LookupService
{
    public function __construct(ReappraisalElementRepositoryInterface $records)
    {
        parent::__construct($records);
    }
}
