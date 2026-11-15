<?php

namespace Modules\Accounting\Http\Requests\Admin;

class PaymentVoucherRequest extends VoucherRequest
{
    protected function accountLabel(): string
    {
        return __('Paid From');
    }
}
