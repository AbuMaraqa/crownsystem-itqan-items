<?php

namespace Modules\Accounting\Repositories;

use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Repositories\Concerns\ReadsVouchers;
use Modules\Accounting\Repositories\Contracts\ReceiptVoucherRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class ReceiptVoucherRepository extends BaseEloquentRepository implements ReceiptVoucherRepositoryInterface
{
    use ReadsVouchers;

    public function __construct(ReceiptVoucher $model)
    {
        parent::__construct($model);
    }
}
