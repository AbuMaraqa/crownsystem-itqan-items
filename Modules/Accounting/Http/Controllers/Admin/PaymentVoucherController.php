<?php

namespace Modules\Accounting\Http\Controllers\Admin;

use Modules\Accounting\Enums\PaymentVoucherStatus;
use Modules\Accounting\Http\Requests\Admin\PaymentVoucherRequest;
use Modules\Accounting\Repositories\Contracts\PaymentVoucherRepositoryInterface;
use Modules\Accounting\Services\CurrencyExchangeRateService;
use Modules\Accounting\Services\PaymentVoucherService;

class PaymentVoucherController extends VoucherController
{
    public function __construct(
        PaymentVoucherRepositoryInterface $vouchers,
        CurrencyExchangeRateService $rates,
        private readonly PaymentVoucherService $voucherService,
    ) {
        parent::__construct($vouchers, $rates);
    }

    protected function page(): string
    {
        return 'Accounting/PaymentVoucher';
    }

    protected function routes(): string
    {
        return 'accounting.payment-vouchers';
    }

    protected function sequence(): array
    {
        return ['payment_voucher', 'PMT'];
    }

    protected function service(): PaymentVoucherService
    {
        return $this->voucherService;
    }

    protected function request(): string
    {
        return PaymentVoucherRequest::class;
    }

    protected function messages(): array
    {
        return [
            'created' => __('Payment voucher created successfully'),
            'updated' => __('Payment voucher updated successfully'),
            'posted' => __('Payment voucher posted successfully'),
            'cancelled' => __('Payment voucher cancelled successfully.'),
            'deleted' => __('Payment voucher deleted successfully'),
        ];
    }

    protected function statusOptions(): array
    {
        return PaymentVoucherStatus::options();
    }
}
