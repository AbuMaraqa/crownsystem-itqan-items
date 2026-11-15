<?php

namespace Modules\Accounting\Http\Requests\Admin;

class ReceiptVoucherRequest extends VoucherRequest
{
    protected function accountLabel(): string
    {
        return __('Received Into');
    }
}
