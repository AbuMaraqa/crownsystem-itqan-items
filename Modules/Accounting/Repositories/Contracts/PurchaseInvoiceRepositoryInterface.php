<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface PurchaseInvoiceRepositoryInterface extends RepositoryInterface
{
    /**
     * The purchase invoices table: supplier (with avatar), warehouse, currency, tax and journal entry.
     */
    public function tableQuery(): Builder;

    /**
     * An invoice with everything its page shows: lines with products and pieces, order, quotation,
     * supplier, warehouse, currency, tax and journal entry.
     */
    public function findForPage(int $id): PurchaseInvoice;

    /**
     * The supplier's purchase orders that can still be invoiced (not cancelled, no active invoice).
     *
     * @return list<array{value: int, label: string}>
     */
    public function invoiceableOrders(int $supplierId, string $search = ''): array;
}
