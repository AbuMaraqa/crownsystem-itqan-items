<?php

namespace Modules\Accounting\Repositories;

use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Repositories\Concerns\ReadsVouchers;
use Modules\Accounting\Repositories\Contracts\PaymentVoucherRepositoryInterface;
use Modules\Core\Repositories\BaseEloquentRepository;

class PaymentVoucherRepository extends BaseEloquentRepository implements PaymentVoucherRepositoryInterface
{
    use ReadsVouchers;

    public function __construct(PaymentVoucher $model)
    {
        parent::__construct($model);
    }
}
