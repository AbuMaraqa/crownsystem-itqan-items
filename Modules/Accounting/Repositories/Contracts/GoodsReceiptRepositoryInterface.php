<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface GoodsReceiptRepositoryInterface extends RepositoryInterface
{
    /**
     * The goods receipts table: purchase order, supplier (with avatar), warehouse and creator.
     */
    public function tableQuery(): Builder;

    /**
     * A receipt with everything its page shows: order, supplier, warehouse, lines with their
     * location, journal entry, poster and creator.
     */
    public function findForPage(int $id): GoodsReceipt;

    /**
     * Confirmed warehouse purchase orders that can be received, by number or supplier.
     *
     * @return list<array<string, mixed>>
     */
    public function receivableOrders(string $search, int $limit = 15): array;
}
