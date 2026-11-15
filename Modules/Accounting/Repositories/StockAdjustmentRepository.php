<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\StockAdjustment;
use Modules\Accounting\Repositories\Contracts\StockAdjustmentRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class StockAdjustmentRepository extends BaseEloquentRepository implements StockAdjustmentRepositoryInterface
{
    public function __construct(StockAdjustment $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['warehouse.translations', 'createdBy.media']);
    }

    public function findForPage(int $id): StockAdjustment
    {
        /** @var StockAdjustment */
        return $this->query()
            ->with(['warehouse.translations', 'items.location', 'journalEntry', 'postedBy', 'createdBy'])
            ->findOrFail($id);
    }
}
