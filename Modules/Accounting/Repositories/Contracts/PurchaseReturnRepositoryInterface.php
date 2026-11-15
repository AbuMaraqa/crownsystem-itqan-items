<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseReturn;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface PurchaseReturnRepositoryInterface extends RepositoryInterface
{
    /**
     * The purchase returns table: invoice, supplier (with avatar), warehouse, currency and tax.
     */
    public function tableQuery(): Builder;

    /**
     * A return with everything its page shows: lines with pieces, invoice, supplier, warehouse,
     * currency, tax and journal entry.
     */
    public function findForPage(int $id): PurchaseReturn;

    /**
     * The supplier's posted purchase invoices, the only ones a return is made from.
     *
     * @return list<array{value: int, label: string}>
     */
    public function returnableInvoices(int $supplierId, string $search = ''): array;

    /**
     * One posted purchase invoice (of the supplier when given), with its tax.
     */
    public function returnableInvoice(int $id, ?int $supplierId = null): ?PurchaseInvoice;
}
