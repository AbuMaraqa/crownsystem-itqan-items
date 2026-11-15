<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface PurchaseOrderRepositoryInterface extends RepositoryInterface
{
    /**
     * The purchase orders table: supplier (with avatar), warehouse and invoices.
     */
    public function tableQuery(): Builder;

    /**
     * An order with everything its page shows: lines with products and pieces, supplier, warehouse,
     * direct-shipment customer and sales order, invoices and attachments.
     */
    public function findForPage(int $id): PurchaseOrder;

    /**
     * Sales orders a direct shipment can be for: confirmed or later, of the customer when given.
     *
     * @return list<array{value: int, label: string}>
     */
    public function directSalesOrderOptions(?int $customerId): array;
}
