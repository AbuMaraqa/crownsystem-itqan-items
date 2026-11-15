<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\StockTransfer;
use Modules\Accounting\Repositories\Contracts\StockTransferRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class StockTransferRepository extends BaseEloquentRepository implements StockTransferRepositoryInterface
{
    public function __construct(StockTransfer $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['fromWarehouse.translations', 'toWarehouse.translations', 'createdBy.media']);
    }

    public function findForPage(int $id): StockTransfer
    {
        /** @var StockTransfer */
        return $this->query()
            ->with(['fromWarehouse.translations', 'toWarehouse.translations', 'items.fromLocation', 'items.toLocation', 'journalEntry', 'postedBy', 'createdBy'])
            ->findOrFail($id);
    }
}
