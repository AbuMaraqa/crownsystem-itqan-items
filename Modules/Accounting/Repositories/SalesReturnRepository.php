<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Accounting\Repositories\Contracts\SalesReturnRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class SalesReturnRepository extends BaseEloquentRepository implements SalesReturnRepositoryInterface
{
    public function __construct(SalesReturn $model)
    {
        parent::__construct($model);
    }

    public function tableQuery(): Builder
    {
        return $this->query()
            ->with(['salesInvoice:id,reference_no', 'client.media', 'warehouse.translations', 'currency', 'tax', 'journalEntry:id,status']);
    }

    public function findForPage(int $id): SalesReturn
    {
        /** @var SalesReturn */
        return $this->query()
            ->with(['items.product.translations', 'items.pieces', 'journalEntry', 'salesInvoice', 'client', 'warehouse.translations', 'currency', 'tax'])
            ->findOrFail($id);
    }
}
