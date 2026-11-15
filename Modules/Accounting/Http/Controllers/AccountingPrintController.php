<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Routing\Controller;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Accounting\Services\AccountingPdfService;

class AccountingPrintController extends Controller
{
    public function __construct(private readonly AccountingPdfService $pdf) {}

    public function salesReturn(SalesReturn $salesReturn)
    {
        return $this->pdf->salesReturn($salesReturn);
    }

    public function receiptVoucher(ReceiptVoucher $receiptVoucher)
    {
        return $this->pdf->receiptVoucher($receiptVoucher);
    }

    public function paymentVoucher(PaymentVoucher $paymentVoucher)
    {
        return $this->pdf->paymentVoucher($paymentVoucher);
    }
}
