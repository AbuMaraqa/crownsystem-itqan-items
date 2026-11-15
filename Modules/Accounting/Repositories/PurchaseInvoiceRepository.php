<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Repositories\Contracts\PurchaseInvoiceRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class PurchaseInvoiceRepository extends BaseEloquentRepository implements PurchaseInvoiceRepositoryInterface
{
    public function __construct(PurchaseInvoice $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['client.media', 'warehouse.translations', 'currency', 'tax', 'journalEntry:id,entry_number']);
    }

    public function findForPage(int $id): PurchaseInvoice
    {
        /** @var PurchaseInvoice */
        return $this->query()
            ->with([
                'items' => fn ($items) => $items->orderBy('id'),
                'items.product.translations',
                'items.pieces',
                'journalEntry',
                'purchaseOrder',
                'purchaseQuotation',
                'client',
                'warehouse.translations',
                'currency',
                'tax',
            ])
            ->findOrFail($id);
    }

    public function invoiceableOrders(int $supplierId, string $search = ''): array
    {
        $search = trim($search);

        return PurchaseOrder::query()
            ->where('client_id', $supplierId)
            ->where('status', '!=', PurchaseOrderStatus::CANCELLED->value())
            ->whereDoesntHave('activePurchaseInvoices')
            ->when($search !== '', fn (Builder $query) => $query->whereLike('reference_no', '%'.addcslashes($search, '%_\\').'%'))
            ->latest('order_date')
            ->limit(50)
            ->pluck('reference_no', 'id')
            ->map(fn ($reference, $id): array => ['value' => (int) $id, 'label' => (string) $reference])
            ->values()
            ->all();
    }
}
