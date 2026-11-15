<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseReturn;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Repositories\Contracts\PurchaseReturnRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class PurchaseReturnRepository extends BaseEloquentRepository implements PurchaseReturnRepositoryInterface
{
    public function __construct(PurchaseReturn $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()->with(['purchaseInvoice:id,reference_no', 'client.media', 'warehouse.translations', 'currency', 'tax']);
    }

    public function findForPage(int $id): PurchaseReturn
    {
        /** @var PurchaseReturn */
        return $this->query()
            ->with([
                'items.product.translations',
                'items.pieces',
                'journalEntry',
                'purchaseInvoice',
                'client',
                'warehouse.translations',
                'currency',
                'tax',
            ])
            ->findOrFail($id);
    }

    public function returnableInvoices(int $supplierId, string $search = ''): array
    {
        $search = trim($search);

        return $this->postedInvoices($supplierId)
            ->when($search !== '', fn (Builder $query) => $query->whereLike('reference_no', '%'.addcslashes($search, '%_\\').'%'))
            ->latest('invoice_date')
            ->limit(50)
            ->pluck('reference_no', 'id')
            ->map(fn ($reference, $id): array => ['value' => (int) $id, 'label' => (string) $reference])
            ->values()
            ->all();
    }

    public function returnableInvoice(int $id, ?int $supplierId = null): ?PurchaseInvoice
    {
        /** @var PurchaseInvoice|null */
        return $this->postedInvoices($supplierId)->with('tax')->whereKey($id)->first();
    }

    /**
     * يُرجع من فاتورة مرحّلة فقط.
     */
    private function postedInvoices(?int $supplierId): Builder
    {
        return PurchaseInvoice::query()
            ->when($supplierId, fn (Builder $query, int $id) => $query->where('client_id', $id))
            ->where('status', PurchaseInvoiceStatus::POSTED->value());
    }
}
