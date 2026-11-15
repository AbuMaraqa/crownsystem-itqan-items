<?php

namespace Modules\Accounting\Services;

use Modules\Accounting\Repositories\Contracts\ClearanceAttachmentTypeRepositoryInterface;

/**
 * انواع مرفقات التخليص في الطلبية التجميعية.
 */
class ClearanceAttachmentTypeService extends LookupService
{
    public function __construct(ClearanceAttachmentTypeRepositoryInterface $records)
    {
        parent::__construct($records);
    }
}
