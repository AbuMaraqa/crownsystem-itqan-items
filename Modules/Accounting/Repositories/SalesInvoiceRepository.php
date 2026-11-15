<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Repositories\Contracts\SalesInvoiceRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class SalesInvoiceRepository extends BaseEloquentRepository implements SalesInvoiceRepositoryInterface
{
    public function __construct(SalesInvoice $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()
            ->with(['salesOrder:id,reference_no', 'client.media', 'warehouse.translations', 'currency', 'tax', 'journalEntry:id,status']);
    }

    public function findForPage(int $id): SalesInvoice
    {
        /** @var SalesInvoice */
        return $this->query()
            ->with([
                'items' => fn ($items) => $items->orderBy('id'),
                'items.product.translations',
                'items.pieces.product',
                'items.serviceProvider',
                'journalEntry',
                'salesOrder.customerMaterials',
                'client',
                'warehouse.translations',
                'currency',
                'tax',
                'cancelledBy',
            ])
            ->findOrFail($id);
    }
}
