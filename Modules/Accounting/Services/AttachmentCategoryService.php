<?php

namespace Modules\Accounting\Services;

use Modules\Accounting\Repositories\Contracts\AttachmentCategoryRepositoryInterface;

/**
 * تصنيفات المرفقات: تظهر خيارات عند ارفاق ملف في الطلبية التجميعية.
 */
class AttachmentCategoryService extends LookupService
{
    public function __construct(AttachmentCategoryRepositoryInterface $records)
    {
        parent::__construct($records);
    }
}
