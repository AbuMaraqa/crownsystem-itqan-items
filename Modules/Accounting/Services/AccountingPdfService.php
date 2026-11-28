<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Entities\ConsolidatedPurchaseOrder;
use Modules\Accounting\Entities\PaymentVoucher;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\PurchaseQuotation;
use Modules\Accounting\Entities\PurchaseReturn;
use Modules\Accounting\Entities\ReceiptVoucher;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\SalesQuotation;
use Modules\Accounting\Entities\SalesReturn;
use Modules\Core\Entities\User;
use Modules\Core\Services\PdfService;

class AccountingPdfService extends PdfService
{
    public function salesInvoice(SalesInvoice $invoice)
    {
        $invoice->loadMissing(['client', 'currency', 'tax', 'items.pieces.product', 'salesOrder.customerMaterials']);

        return $this->streamPdf(
            'accounting::pdf.sales-invoice',
            ['invoice' => $invoice],
            $invoice->reference_no.'.pdf'
        );
    }

    public function purchaseOrder(PurchaseOrder $order)
    {
        $order->loadMissing(['client', 'warehouse', 'items.product', 'items.pieces.product']);

        return $this->streamPdf(
            'accounting::pdf.purchase-order',
            ['order' => $order],
            $order->reference_no.'.pdf'
        );
    }

    public function salesOrder(SalesOrder $order)
    {
        $order->loadMissing(['client', 'warehouse', 'currency', 'tax', 'createdBy', 'items.product', 'items.pieces.product', 'customerMaterials']);

        return $this->streamPdf(
            'accounting::pdf.sales-order',
            ['order' => $order],
            $order->reference_no.'.pdf'
        );
    }

    public function purchaseInvoice(PurchaseInvoice $invoice)
    {
        return $this->tradeDocument($invoice, __('Purchase Invoice'), __('Supplier'), __('Invoice Date'), 'invoice_date', $invoice->purchaseOrder?->reference_no, __('Purchase Order'));
    }

    public function salesReturn(SalesReturn $return)
    {
        return $this->tradeDocument($return, __('Sales Return'), __('Client'), __('Return Date'), 'return_date', $return->salesInvoice?->reference_no, __('Sales Invoice'));
    }

    public function purchaseReturn(PurchaseReturn $return)
    {
        return $this->tradeDocument($return, __('Purchase Return'), __('Supplier'), __('Return Date'), 'return_date', $return->purchaseInvoice?->reference_no, __('Purchase Invoice'));
    }

    public function receiptVoucher(ReceiptVoucher $voucher)
    {
        return $this->voucher($voucher, __('Receipt Voucher'), __('Received From'));
    }

    public function paymentVoucher(PaymentVoucher $voucher)
    {
        return $this->voucher($voucher, __('Payment Voucher'), __('Paid To'));
    }

    /**
     * فاتورة المشتريات والمردودات بقالب واحد: بنود ثم مجاميع.
     */
    /**
     * عرض سعر الشراء بسعر المورد لكل سطر من طلبيته.
     */
    public function purchaseQuotation(PurchaseQuotation $quotation)
    {
        return $this->tradeDocument(
            $quotation,
            __('Purchase Quotation'),
            __('Supplier'),
            __('Quotation Date'),
            'quotation_date',
            $quotation->purchaseOrder?->reference_no,
            __('Purchase Order'),
            ['column' => 'quotation_discount', 'label' => __('Quotation Discount')],
            [[__('Supplier Quotation No'), $quotation->supplier_reference], [__('Valid Until'), $quotation->valid_until?->format('d/m/Y')]]
        );
    }

    /**
     * عرض سعر البيع بسطوره واصنافها الفرعية، بنفس قالب المستندات التجارية.
     */
    public function salesQuotation(SalesQuotation $quotation)
    {
        return $this->tradeDocument(
            $quotation,
            __('Sales Quotation'),
            __('Client'),
            __('Quotation Date'),
            'quotation_date',
            $quotation->salesOrders()->latest('id')->value('reference_no'),
            __('Sales Order'),
            ['column' => 'quotation_discount', 'label' => __('Quotation Discount')],
            [[__('Valid Until'), $quotation->valid_until?->format('d/m/Y')]]
        );
    }

    /**
     * @param  array{column: string, label: string}|null  $discount  عمود خصم المستند العام ان لم يكن invoice_discount
     * @param  array<int, array{0: string, 1: ?string}>  $extraMeta
     */
    private function tradeDocument(Model $document, string $title, string $partyLabel, string $dateLabel, string $dateColumn, ?string $sourceReference, string $sourceLabel, ?array $discount = null, array $extraMeta = [])
    {
        // سطور صنف الوزن تُطبع باصنافها الفرعية في المستندات التي تحفظها.
        $items = method_exists($document->items()->getRelated(), 'pieces') ? 'items.pieces.product' : 'items';

        $document->loadMissing(array_filter(['client', method_exists($document, 'warehouse') ? 'warehouse' : null, 'currency', 'tax', $items]));

        return $this->streamPdf('accounting::pdf.trade-document', [
            'document' => $document,
            'docTitle' => $title,
            'discountColumn' => $discount['column'] ?? 'invoice_discount',
            'discountLabel' => $discount['label'] ?? __('Invoice Discount'),
            'party' => ['name' => $document->client?->name, 'detail' => $partyLabel],
            'meta' => [
                [$dateLabel, $document->{$dateColumn}?->format('d/m/Y')],
                [__('Reference No'), $document->reference_no],
                [$sourceLabel, $sourceReference],
                [__('Currency'), $document->currency?->code],
                [__('Status'), $document->status?->getLabel()],
                ...$extraMeta,
            ],
        ], $document->reference_no.'.pdf');
    }

    private function voucher(Model $voucher, string $title, string $partyLabel)
    {
        $voucher->loadMissing(['client', 'account', 'currency']);

        return $this->streamPdf('accounting::pdf.voucher', [
            'voucher' => $voucher,
            'docTitle' => $title,
            'partyLabel' => $partyLabel,
        ], $voucher->reference_no.'.pdf');
    }

    public function consolidatedPurchaseOrderQuoteRequest(ConsolidatedPurchaseOrder $order, User $supplier)
    {
        $order->loadMissing('items');

        return $this->streamPdf(
            'accounting::pdf.consolidated-purchase-order-quote-request',
            ['order' => $order, 'supplier' => $supplier],
            $order->reference_no.'-'.__('Quote Request').'.pdf'
        );
    }
}
