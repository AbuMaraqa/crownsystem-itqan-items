<?php

namespace Modules\Accounting\Repositories;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Entities\PaymentAllocation;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Entities\PurchaseReturn;
use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Accounting\Repositories\Contracts\PaymentAllocationRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class PaymentAllocationRepository extends BaseEloquentRepository implements PaymentAllocationRepositoryInterface
{
    /**
     * المستندات التي تسدد فواتير، بمفتاحها في الرابط (نفس اسم مسار صفحاتها).
     */
    private const SOURCES = [
        'receipt-vouchers' => ReceiptVoucher::class,
        'payment-vouchers' => PaymentVoucher::class,
        'sales-returns' => SalesReturn::class,
        'purchase-returns' => PurchaseReturn::class,
    ];

    public function __construct(PaymentAllocation $model)
    {
        parent::__construct($model);
    }

    public function sourceTypes(): array
    {
        return array_keys(self::SOURCES);
    }

    public function sourceTypeOf(Model $record): ?string
    {
        $type = array_search($record::class, self::SOURCES, true);

        return $type === false ? null : $type;
    }

    public function findSourceOrFail(string $type, int $id): Model
    {
        abort_unless(isset(self::SOURCES[$type]), 404);

        return self::SOURCES[$type]::query()->findOrFail($id);
    }
}
