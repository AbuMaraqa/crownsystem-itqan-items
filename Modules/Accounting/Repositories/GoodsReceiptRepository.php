<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Enums\PurchaseOrderDeliveryType;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Repositories\Contracts\GoodsReceiptRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class GoodsReceiptRepository extends BaseEloquentRepository implements GoodsReceiptRepositoryInterface
{
    public function __construct(GoodsReceipt $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['purchaseOrder:id,reference_no', 'client.media', 'warehouse.translations', 'createdBy.media']);
    }

    public function findForPage(int $id): GoodsReceipt
    {
        /** @var GoodsReceipt */
        return $this->query()
            ->with(['purchaseOrder.client', 'warehouse.translations', 'client', 'items.location', 'journalEntry', 'postedBy', 'createdBy'])
            ->findOrFail($id);
    }

    public function receivableOrders(string $search, int $limit = 15): array
    {
        $term = '%'.addcslashes(trim($search), '%_\\').'%';

        return PurchaseOrder::query()
            ->with('client')
            ->whereIn('status', [PurchaseOrderStatus::CONFIRMED->value(), PurchaseOrderStatus::INVOICED->value(), PurchaseOrderStatus::RECEIVED->value()])
            ->where('delivery_type', PurchaseOrderDeliveryType::WAREHOUSE->value)
            ->when(trim($search) !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->whereLike('reference_no', $term)
                ->orWhereHas('client', fn (Builder $client) => $client->whereLike('name', $term))))
            ->latest('order_date')
            ->limit($limit)
            ->get()
            ->map(fn (PurchaseOrder $order) => [
                'value' => $order->id,
                'label' => (string) $order->reference_no,
                'party' => $order->client?->name,
                'date' => $order->order_date?->format('d/m/Y'),
                'status' => $order->status?->getLabel(),
            ])
            ->values()
            ->all();
    }
}
