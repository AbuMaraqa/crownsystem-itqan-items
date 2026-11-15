<?php

namespace Modules\Accounting\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface PaymentAllocationRepositoryInterface extends RepositoryInterface
{
    /**
     * The URL key of each document that pays invoices (`receipt-vouchers` => ReceiptVoucher...).
     *
     * @return list<string>
     */
    public function sourceTypes(): array;

    /**
     * The URL key of a source document, or null when the document does not pay invoices.
     */
    public function sourceTypeOf(Model $record): ?string;

    /**
     * The source document (voucher or return) from its URL key and id.
     */
    public function findSourceOrFail(string $type, int $id): Model;
}
