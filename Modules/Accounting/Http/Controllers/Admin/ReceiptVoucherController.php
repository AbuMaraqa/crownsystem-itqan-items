<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use Modules\Accounting\Enums\ReceiptVoucherStatus;
use Modules\Accounting\Http\Requests\Admin\ReceiptVoucherRequest;
use Modules\Accounting\Repositories\Contracts\ReceiptVoucherRepositoryInterface;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Services\ReceiptVoucherService;

class ReceiptVoucherController extends VoucherController
{
    public function __construct(
        ReceiptVoucherRepositoryInterface $vouchers,
        CurrencyExchangeRateService $rates,
        private readonly ReceiptVoucherService $voucherService,
    ) {
        parent::__construct($vouchers, $rates);
    }

    protected function page(): string
    {
        return 'Accounting/ReceiptVoucher';
    }

    protected function routes(): string
    {
        return 'accounting.receipt-vouchers';
    }

    protected function sequence(): array
    {
        return ['receipt_voucher', 'RCT'];
    }

    protected function service(): ReceiptVoucherService
    {
        return $this->voucherService;
    }

    protected function request(): string
    {
        return ReceiptVoucherRequest::class;
    }

    protected function messages(): array
    {
        return [
            'created' => __('Receipt voucher created successfully'),
            'updated' => __('Receipt voucher updated successfully'),
            'posted' => __('Receipt voucher posted successfully'),
            'cancelled' => __('Receipt voucher cancelled successfully.'),
            'deleted' => __('Receipt voucher deleted successfully'),
        ];
    }

    protected function statusOptions(): array
    {
        return ReceiptVoucherStatus::options();
    }
}
