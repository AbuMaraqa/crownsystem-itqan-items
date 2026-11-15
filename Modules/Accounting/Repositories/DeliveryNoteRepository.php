<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Accounting\Repositories\Contracts\DeliveryNoteRepositoryInterface;
use Modules\Accounting\Services\DeliveryNoteService;
use Modules\Core\Repositories\BaseEloquentRepository;

class DeliveryNoteRepository extends BaseEloquentRepository implements DeliveryNoteRepositoryInterface
{
    public function __construct(DeliveryNote $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['salesOrder:id,reference_no', 'client.media', 'warehouse.translations', 'createdBy.media']);
    }

    public function findForPage(int $id): DeliveryNote
    {
        /** @var DeliveryNote */
        return $this->query()
            ->with(['salesOrder.client', 'warehouse.translations', 'client', 'items.location', 'journalEntry', 'postedBy', 'createdBy'])
            ->findOrFail($id);
    }

    public function deliverableOrders(string $search, int $limit = 15): array
    {
        $term = '%'.addcslashes(trim($search), '%_\\').'%';

        return SalesOrder::query()
            ->with('client')
            ->whereIn('status', array_map(fn (SalesOrderStatus $status) => $status->value(), DeliveryNoteService::DELIVERABLE_STATUSES))
            ->when(trim($search) !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->whereLike('reference_no', $term)
                ->orWhereHas('client', fn (Builder $client) => $client->whereLike('name', $term))))
            ->latest('order_date')
            ->limit($limit)
            ->get()
            ->map(fn (SalesOrder $order) => [
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
