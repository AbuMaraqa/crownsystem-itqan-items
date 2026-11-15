<?php

namespace Modules\Accounting\Services;

use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderBankCredit;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrderPayment;

class ConsolidatedPurchaseOrderFinancialFileService
{
    public function createPayment(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrderPayment
    {
        return $order->payments()->create([
            ...$data,
            'created_by' => auth()->id(),
        ]);
    }

    public function updatePayment(ConsolidatedPurchaseOrderPayment $payment, array $data): ConsolidatedPurchaseOrderPayment
    {
        $payment->update($data);

        return $payment->refresh();
    }

    public function deletePayment(ConsolidatedPurchaseOrderPayment $payment): void
    {
        $payment->delete();
    }

    public function createBankCredit(ConsolidatedPurchaseOrder $order, array $data): ConsolidatedPurchaseOrderBankCredit
    {
        return $order->bankCredits()->create([
            ...$data,
            'created_by' => auth()->id(),
        ]);
    }

    public function updateBankCredit(ConsolidatedPurchaseOrderBankCredit $bankCredit, array $data): ConsolidatedPurchaseOrderBankCredit
    {
        $bankCredit->update($data);

        return $bankCredit->refresh();
    }

    public function deleteBankCredit(ConsolidatedPurchaseOrderBankCredit $bankCredit): void
    {
        $bankCredit->delete();
    }
}
