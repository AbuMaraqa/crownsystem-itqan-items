<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\ClearanceAttachmentType;
use Modules\Accounting\Repositories\Contracts\ClearanceAttachmentTypeRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class ClearanceAttachmentTypeRepository extends BaseEloquentRepository implements ClearanceAttachmentTypeRepositoryInterface
{
    public function __construct(ClearanceAttachmentType $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query();
    }
}
