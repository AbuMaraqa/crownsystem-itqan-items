<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\AttachmentCategory;
use Modules\Accounting\Repositories\Contracts\AttachmentCategoryRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class AttachmentCategoryRepository extends BaseEloquentRepository implements AttachmentCategoryRepositoryInterface
{
    public function __construct(AttachmentCategory $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['createdBy.media']);
    }
}
