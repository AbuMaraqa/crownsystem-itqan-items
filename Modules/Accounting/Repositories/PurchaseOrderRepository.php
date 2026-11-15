<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Repositories\Contracts\PurchaseOrderRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class PurchaseOrderRepository extends BaseEloquentRepository implements PurchaseOrderRepositoryInterface
{
    public function __construct(PurchaseOrder $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()
            ->with(['client.media', 'warehouse.translations', 'purchaseInvoices:id,purchase_order_id,status'])
            ->withCount('items');
    }

    public function findForPage(int $id): PurchaseOrder
    {
        /** @var PurchaseOrder */
        return $this->query()
            ->with([
                'items' => fn ($items) => $items->orderBy('id'),
                'items.product.translations',
                'items.pieces.product',
                'purchaseInvoices',
                'client',
                'warehouse.translations',
                'directCustomer',
                'directSalesOrder',
                'media',
            ])
            ->findOrFail($id);
    }

    public function directSalesOrderOptions(?int $customerId): array
    {
        return SalesOrder::query()
            ->when($customerId, fn (Builder $query, int $id) => $query->where('client_id', $id))
            ->whereNotIn('status', [SalesOrderStatus::CANCELLED->value(), SalesOrderStatus::DRAFT->value()])
            ->latest('order_date')
            ->limit(50)
            ->pluck('reference_no', 'id')
            ->map(fn ($reference, $id): array => ['value' => (int) $id, 'label' => (string) $reference])
            ->values()
            ->all();
    }
}
