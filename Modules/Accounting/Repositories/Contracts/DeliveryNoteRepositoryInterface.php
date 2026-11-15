<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface DeliveryNoteRepositoryInterface extends RepositoryInterface
{
    /**
     * The delivery notes table: sales order, customer (with avatar), warehouse and creator.
     */
    public function tableQuery(): Builder;

    /**
     * A delivery note with everything its page shows.
     */
    public function findForPage(int $id): DeliveryNote;

    /**
     * Sales orders in a deliverable status, by number or customer.
     *
     * @return list<array<string, mixed>>
     */
    public function deliverableOrders(string $search, int $limit = 15): array;
}
