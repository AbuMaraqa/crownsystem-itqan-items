<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\SalesQuotation;
use Modules\Accounting\Repositories\Contracts\SalesQuotationRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class SalesQuotationRepository extends BaseEloquentRepository implements SalesQuotationRepositoryInterface
{
    public function __construct(SalesQuotation $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()
            ->with(['client.media', 'warehouse.translations', 'currency', 'salesOrders:id,sales_quotation_id'])
            ->withCount('items');
    }

    public function findForPage(int $id): SalesQuotation
    {
        /** @var SalesQuotation */
        return $this->query()
            ->with([
                'items' => fn ($items) => $items->orderBy('id'),
                'items.product.translations',
                'items.pieces',
                'salesOrders',
                'client',
                'warehouse.translations',
                'currency',
                'tax',
            ])
            ->findOrFail($id);
    }
}
