<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Repositories\Contracts\ConsolidatedPurchaseOrderRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class ConsolidatedPurchaseOrderRepository extends BaseEloquentRepository implements ConsolidatedPurchaseOrderRepositoryInterface
{
    public function __construct(ConsolidatedPurchaseOrder $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['client.media', 'warehouse.translations', 'currency']);
    }

    public function findForPage(int $id): ConsolidatedPurchaseOrder
    {
        /** @var ConsolidatedPurchaseOrder */
        return $this->query()->with(['client', 'currency', 'createdBy', 'followedUpBy'])->findOrFail($id);
    }
}
