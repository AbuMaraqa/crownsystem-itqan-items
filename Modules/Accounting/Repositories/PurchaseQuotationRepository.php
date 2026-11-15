<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Repositories\Contracts\PurchaseQuotationRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class PurchaseQuotationRepository extends BaseEloquentRepository implements PurchaseQuotationRepositoryInterface
{
    public function __construct(PurchaseQuotation $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['purchaseOrder:id,reference_no', 'client.media', 'currency', 'createdBy.media']);
    }

    public function findForPage(int $id): PurchaseQuotation
    {
        /** @var PurchaseQuotation */
        return $this->query()
            ->with([
                'items' => fn ($items) => $items->orderBy('id'),
                'items.product.translations',
                'purchaseOrder.client',
                'purchaseOrder.items.product.translations',
                'purchaseOrder.items.pieces',
                'client',
                'currency',
                'tax',
                'approvedBy',
                'cancelledBy',
                'createdBy',
                'purchaseInvoices',
            ])
            ->findOrFail($id);
    }

    public function quotableOrders(string $search = ''): array
    {
        $search = trim($search);

        return $this->orders()
            ->whereDoesntHave('purchaseQuotations', fn (Builder $query) => $query->active())
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn (Builder $query) => $query
                    ->whereLike('reference_no', $term)
                    ->orWhereHas('client', fn (Builder $client) => $client->whereLike('name', $term)));
            })
            ->latest('order_date')
            ->limit(15)
            ->get()
            ->map(fn (PurchaseOrder $order) => [
                'value' => $order->id,
                'label' => $order->reference_no.' — '.($order->client?->name ?? '-'),
                'supplier' => $order->client?->name,
                'date' => $order->order_date?->format('d/m/Y'),
                'status' => $order->status?->getLabel(),
            ])
            ->values()
            ->all();
    }

    public function openOrder(int $id): ?PurchaseOrder
    {
        /** @var PurchaseOrder|null */
        return $this->orders()->with(['warehouse.translations', 'items.product.translations', 'items.pieces'])->whereKey($id)->first();
    }

    private function orders(): Builder
    {
        return PurchaseOrder::query()
            ->with('client')
            ->whereNotIn('status', [PurchaseOrderStatus::CANCELLED->value(), PurchaseOrderStatus::INVOICED->value()]);
    }
}
