<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\ProductionOrder;
use Modules\Accounting\Repositories\Contracts\ProductionOrderRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class ProductionOrderRepository extends BaseEloquentRepository implements ProductionOrderRepositoryInterface
{
    public function __construct(ProductionOrder $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['salesOrder:id,reference_no', 'client.media', 'currency']);
    }

    public function findForPage(int $id): ProductionOrder
    {
        /** @var ProductionOrder */
        return $this->query()
            ->with(['salesOrder.client', 'client', 'currency', 'materials', 'labor.worker', 'services.serviceProvider'])
            ->findOrFail($id);
    }
}
