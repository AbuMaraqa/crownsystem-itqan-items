<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Repositories\Contracts\SalesOrderRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class SalesOrderRepository extends BaseEloquentRepository implements SalesOrderRepositoryInterface
{
    public function __construct(SalesOrder $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()
            ->with(['salesQuotation:id,reference_no', 'orderType:id,name', 'client.media', 'warehouse.translations', 'currency', 'salesInvoices:id,sales_order_id']);
    }

    public function findForPage(int $id): SalesOrder
    {
        /** @var SalesOrder */
        return $this->query()
            ->with([
                'items' => fn ($items) => $items->orderBy('id'),
                'items.product.translations',
                'items.pieces.product',
                'items.serviceProvider',
                'customerMaterials' => fn ($materials) => $materials->orderBy('sort_order')->orderBy('id'),
                'salesQuotation',
                'salesInvoices',
                'directSupplier',
                'client',
                'warehouse.translations',
                'currency',
                'tax',
            ])
            ->findOrFail($id);
    }
}
