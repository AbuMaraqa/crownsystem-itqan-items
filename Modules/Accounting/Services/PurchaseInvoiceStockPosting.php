<?php

namespace Modules\Accounting\Services;

use Modules\Accounting\Entities\JournalLine;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;

/**
 * الطرف المدين لقيد فاتورة الشراء (صافي المشتريات قبل الضريبة) حسب طلبيتها:
 *
 * - طلبية للمخزن: البضاعة دخلت (او ستدخل) المخزون بسند استلام قُيِّد الى حـ/ «بضاعة مستلمة
 *   لم تصل فواتيرها». الفاتورة تُقفل هذا الحساب بكلفة الاستلام الفعلية (لا بالكمية المطلوبة)
 *   ناقص ما أقفلته فواتير سابقة، وفرق السعر او سعر الصرف يذهب (مديناً او دائناً حسب اشارته)
 *   الى «فروقات اسعار المشتريات». الخدمات تبقى على حساب المشتريات خارج فرق السعر.
 *   سطرا الاقفال والفرق بالعملة الاساسية؛ يحمل كل سطر مفتاح covers = ما يغطيه بعملة الفاتورة.
 * - شحن مباشر للزبون: البضاعة لم تدخل المخزن فتُحمَّل مباشرة على تكلفة البضاعة المباعة.
 * - فاتورة بلا طلبية: اصنافها تدخل مخزن الفاتورة مباشرة فتُحمَّل على حساب مخزونه،
 *   والخدمات (وما بقي) على حساب المشتريات.
 *
 * يعيد null حين تُحمَّل الفاتورة كلها على حساب المشتريات؛ وما لا تغطيه السطور
 * المعادة يُكمله PurchaseInvoiceService على حساب المشتريات.
 */
class PurchaseInvoiceStockPosting
{
    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function debitLines(PurchaseInvoice $invoice, float $purchaseAmount): ?array
    {
        if ($purchaseAmount <= 0) {
            return null;
        }

        $order = $invoice->purchase_order_id ? PurchaseOrder::query()->find($invoice->purchase_order_id) : null;
        $accounts = app(InventoryAccounts::class);
        $base = [
            'branch_id' => $invoice->branch_id,
            'currency_id' => $invoice->currency_id,
            'exchange_rate' => $invoice->exchange_rate,
            'credit' => 0,
            'created_by' => $invoice->created_by ?? auth()->id(),
        ];

        if (! $order) {
            return app(InvoiceStockService::class)->purchaseDebitLines($invoice, $purchaseAmount, $base) ?: null;
        }

        if ($order->isDirectShipment()) {
            return [array_merge($base, [
                'account_id' => $accounts->mapped(InventoryAccounts::DIRECT_COST_KEY),
                'debit' => $purchaseAmount,
                'description' => __('Direct shipment cost').' '.$invoice->reference_no,
            ])];
        }

        // ما يُقفَل من «بضاعة لم تصل فواتيرها» = كلفة الاستلام الفعلية (السندات + الاستلام
        // التلقائي عند الترحيل) ناقص ما أقفلته فواتير مرحّلة سابقة لنفس الطلبية.
        $clearingBase = round(app(GoodsReceiptService::class)->receivableInventoryValue($order) - $this->clearedByOtherInvoices($invoice, $order), 4);

        // سند بقيمة صفر (طلبية بلا سعر) على سطر وزن: التسوية تحمّل قيمة الفاتورة على المخزون
        // مقابل «فروقات اسعار المشتريات»، فلا تذهب الى حساب المشتريات.
        $invoice->loadMissing('items.pieces');
        $hasWeightLines = $invoice->items->contains(fn ($item) => filled($item->purchase_order_item_id) && $item->pieces->isNotEmpty());

        if ($clearingBase < 0 || ($clearingBase == 0.0 && ! $hasWeightLines)) {
            return null;
        }

        // الخدمات لا تدخل المخزون فتبقى على حساب المشتريات (يكملها PurchaseInvoiceService)
        // ولا تُحسب في فرق السعر.
        $rate = (float) $invoice->exchange_rate ?: 1;
        $serviceAmount = min($this->serviceAmount($invoice), $purchaseAmount);
        $goodsAmount = round($purchaseAmount - $serviceAmount, 4);

        // صافي البضاعة بالعملة الاساسية = الدائن للمورد − الضريبة − الخدمات، بنفس تقريب
        // JournalEntryService لكل سطر، فيتوازن القيد تماماً ويُعكس عند الالغاء بلا فروق.
        $goodsBase = round(
            $this->toBase((float) $invoice->total_amount, $rate)
            - ((float) $invoice->tax_amount > 0 ? $this->toBase((float) $invoice->tax_amount, $rate) : 0)
            - ($serviceAmount > 0 ? $this->toBase($serviceAmount, $rate) : 0),
            4
        );
        $varianceBase = round($goodsBase - $clearingBase, 4);

        // سطرا الاقفال والفرق بالعملة الاساسية (كقيد سند الاستلام) فيُصفَّر الحساب بكل عملة.
        $baseLine = array_merge($base, [
            'currency_id' => app(CurrencyExchangeRateService::class)->baseCurrencyId() ?? $invoice->currency_id,
            'exchange_rate' => 1,
        ]);

        $lines = $clearingBase > 0 ? [array_merge($baseLine, [
            'account_id' => $accounts->mapped(InventoryAccounts::GRNI_KEY),
            'debit' => $clearingBase,
            'covers' => $goodsAmount,
            'description' => __('Goods received not invoiced').' '.$order->reference_no,
        ])] : [];

        if ($varianceBase != 0.0) {
            $lines[] = array_merge($baseLine, [
                'account_id' => $accounts->mapped(InventoryAccounts::PRICE_VARIANCE_KEY),
                'debit' => $varianceBase > 0 ? $varianceBase : 0,
                'credit' => $varianceBase < 0 ? -$varianceBase : 0,
                'covers' => $clearingBase > 0 ? 0 : $goodsAmount,
                'description' => __('Purchase price variance').' '.$invoice->reference_no,
            ]);
        }

        return $lines;
    }

    /**
     * صافي سطور الخدمات بعد خصم الفاتورة العام وقبل الضريبة (بعملة الفاتورة).
     */
    public function serviceAmount(PurchaseInvoice $invoice): float
    {
        $invoice->loadMissing('items.product');
        $linesTotal = (float) $invoice->items->sum('line_total');

        if ($linesTotal <= 0) {
            return 0.0;
        }

        $factor = max(($linesTotal - (float) $invoice->invoice_discount) / $linesTotal, 0);

        return round((float) $invoice->items
            ->filter(fn ($item) => $item->product?->isService())
            ->sum(fn ($item) => (float) $item->line_total * $factor), 4);
    }

    /**
     * ما أقفلته فواتير الطلبية المرحّلة الاخرى من «بضاعة لم تصل فواتيرها» (بالعملة الاساسية).
     */
    private function clearedByOtherInvoices(PurchaseInvoice $invoice, PurchaseOrder $order): float
    {
        $entryIds = PurchaseInvoice::query()
            ->where('purchase_order_id', $order->id)
            ->where('status', PurchaseInvoiceStatus::POSTED->value())
            ->whereKeyNot($invoice->id)
            ->whereNotNull('journal_entry_id')
            ->pluck('journal_entry_id');

        if ($entryIds->isEmpty()) {
            return 0.0;
        }

        $lines = JournalLine::query()
            ->whereIn('journal_entry_id', $entryIds)
            ->where('account_id', app(InventoryAccounts::class)->mapped(InventoryAccounts::GRNI_KEY))
            ->get(['debit_base', 'credit_base']);

        return round((float) $lines->sum('debit_base') - (float) $lines->sum('credit_base'), 4);
    }

    private function toBase(float $amount, float $rate): float
    {
        return round(round($amount, 4) * round($rate, 8), 4);
    }
}
