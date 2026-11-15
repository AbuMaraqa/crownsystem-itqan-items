<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface PurchaseQuotationRepositoryInterface extends RepositoryInterface
{
    /**
     * The purchase quotations table: purchase order, supplier (with avatar), currency and creator.
     */
    public function tableQuery(): Builder;

    /**
     * A quotation with everything its page shows: lines, its purchase order with lines and pieces,
     * supplier, currency, tax, approver and invoices.
     */
    public function findForPage(int $id): PurchaseQuotation;

    /**
     * Open purchase orders (not cancelled or invoiced) without an active quotation, by reference or supplier.
     *
     * @return list<array{value: int, label: string, supplier: ?string, date: ?string, status: ?string}>
     */
    public function quotableOrders(string $search = ''): array;

    /**
     * One open purchase order a quotation can be made for, with its supplier and warehouse.
     */
    public function openOrder(int $id): ?PurchaseOrder;
}
