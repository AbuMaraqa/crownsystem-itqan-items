<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Collection;
use Modules\Accounting\Entities\DeliveryNote;
use Modules\Accounting\Entities\DeliveryNoteItem;
use Modules\Accounting\Entities\GoodsReceipt;
use Modules\Accounting\Entities\GoodsReceiptItem;
use Modules\Accounting\Entities\JournalEntry;
use Modules\Accounting\Entities\PurchaseInvoice;
use Modules\Accounting\Entities\PurchaseOrder;
use Modules\Accounting\Entities\SalesInvoice;
use Modules\Accounting\Entities\SalesOrder;
use Modules\Accounting\Entities\StockMovement;
use Modules\Accounting\Entities\Warehouse;
use Modules\Accounting\Enums\InventoryDocumentStatus;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\StockMovementType;
use Modules\Accounting\Services\Concerns\PostsInventoryJournal;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ItemType;

/**
 * حركة المخزون عند ترحيل الفاتورة، حتى لا تبقى بضاعة مفوترة خارج المخزون:
 *
 * - فاتورة شراء من طلبية للمخزن: يُستلم المتبقي من الطلبية بسند استلام مرحّل تلقائياً.
 * - فاتورة مبيعات من طلبية: يُسلَّم المتبقي من الطلبية بسند تسليم مرحّل تلقائياً
 *   (ويُمنع الترحيل ان لم تكفِ الكمية في المخزن).
 * - طلبيات الشحن المباشر للزبون لا تمر بالمخزن.
 * - فاتورة بلا طلبية: تدخل اصنافها مخزن الفاتورة (او تخرج منه) مباشرة؛ فاتورة الشراء
 *   تحمّل قيمتها على حساب مخزون المستودع، وفاتورة المبيعات تقيّد تكلفتها
 *   (من حـ/ تكلفة البضاعة المباعة الى حـ/ مخزون المستودع).
 *
 * سطر الوزن كما في اتقان: الصنف الرئيسي يتحرك بالوزن الفعلي وقيمة السطر (ولا حركة له بلا وزن
 * فعلي)، وكل مقطع يتحرك بعدده بقيمة صفر. الرصيدان لا يُسوَّى احدهما بالآخر.
 *
 * فاتورة من طلبية تعدّل ما تحرك بسندات الاستلام او التسليم: حركة تسوية على الصنف الرئيسي حتى
 * يساوي رصيده الوزن الفعلي في الفاتورة وقيمته مبلغها، بقيد مخزون مقابل «فروقات اسعار
 * المشتريات» (الشراء) او «تكلفة البضاعة المباعة» (البيع).
 */
class InvoiceStockService
{
    use PostsInventoryJournal;

    public function receivePurchaseInvoice(PurchaseInvoice $invoice): void
    {
        $order = $invoice->purchase_order_id ? PurchaseOrder::query()->find($invoice->purchase_order_id) : null;

        if ($order) {
            if (! $order->isDirectShipment()) {
                app(GoodsReceiptService::class)->receiveOutstanding($order, [
                    'warehouse_id' => $this->resolveWarehouseId($invoice->warehouse_id ?: $order->warehouse_id),
                    'branch_id' => $invoice->branch_id ?? $order->branch_id,
                    'receipt_date' => $invoice->invoice_date->toDateString(),
                    'supplier_document_no' => $invoice->reference_no,
                    'purchase_invoice_id' => $invoice->id,
                    'notes' => __('Received automatically with purchase invoice :reference', ['reference' => $invoice->reference_no]),
                ]);

                $this->trueUpPurchaseInvoice($invoice, $order);
            }

            return;
        }

        $lines = $this->stockLines($invoice);

        if ($lines === []) {
            return;
        }

        $warehouseId = $this->stockWarehouseId($invoice);
        $rate = (float) $invoice->exchange_rate ?: 1;
        $ledger = app(StockLedgerService::class);
        $layout = app(WarehouseLayoutService::class);

        foreach ($lines as $line) {
            $locationId = $layout->primaryLocationId($line['product_id'], $warehouseId);

            $ledger->receive([
                'product_id' => $line['product_id'],
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'quantity' => $line['quantity'],
                'weight' => $line['weight'],
                'unit_cost' => round($line['amount'] * $rate / $line['quantity'], 6),
                'total_cost' => round($line['amount'] * $rate, 4),
                'type' => StockMovementType::RECEIPT,
                'date' => $invoice->invoice_date->toDateString(),
                'source' => $invoice,
                'source_line_id' => $line['item_id'],
                'reference_no' => $invoice->reference_no,
                'description' => __('Purchase invoice').' '.$invoice->reference_no,
            ]);
        }
    }

    public function issueSalesInvoice(SalesInvoice $invoice): void
    {
        $order = $invoice->sales_order_id ? SalesOrder::query()->find($invoice->sales_order_id) : null;

        if ($order) {
            $delivery = app(DeliveryNoteService::class);

            if (! $delivery->isDirectShipment($order)) {
                $delivery->deliverOutstanding($order, [
                    'warehouse_id' => $this->resolveWarehouseId($invoice->warehouse_id ?: $order->warehouse_id),
                    'branch_id' => $invoice->branch_id ?? $order->branch_id,
                    'delivery_date' => $invoice->invoice_date->toDateString(),
                    'sales_invoice_id' => $invoice->id,
                    'notes' => __('Delivered automatically with sales invoice :reference', ['reference' => $invoice->reference_no]),
                ], $this->invoicedKg($invoice));

                $this->trueUpSalesInvoice($invoice, $order);
            }

            return;
        }

        $lines = $this->stockLines($invoice);

        if ($lines === []) {
            return;
        }

        $warehouseId = $this->stockWarehouseId($invoice);
        $ledger = app(StockLedgerService::class);
        $date = $invoice->invoice_date->toDateString();
        $total = 0.0;

        foreach ($lines as $line) {
            $movements = $ledger->issue([
                'product_id' => $line['product_id'],
                'warehouse_id' => $warehouseId,
                'quantity' => $line['quantity'],
                'weight' => $line['weight'] > 0 ? $line['weight'] : null,
                'type' => StockMovementType::DELIVERY,
                'date' => $date,
                'source' => $invoice,
                'source_line_id' => $line['item_id'],
                'reference_no' => $invoice->reference_no,
                'description' => __('Sales invoice').' '.$invoice->reference_no,
            ]);

            $total += (float) $movements->sum(fn (StockMovement $movement) => -(float) $movement->total_cost);
        }

        $total = round($total, 4);
        $accounts = app(InventoryAccounts::class);

        $this->postInventoryJournal($invoice, 'SalesInvoice', $date, __('Cost of Goods Sold').' - '.__('Sales invoice').' '.$invoice->reference_no, [
            ['account_id' => $accounts->mapped(InventoryAccounts::COGS_KEY), 'debit' => $total, 'credit' => 0],
            ['account_id' => $accounts->warehouseAccountId($warehouseId), 'debit' => 0, 'credit' => $total],
        ]);
    }

    /**
     * الغاء فاتورة شراء مرحّلة (يستدعيه PurchaseInvoiceService::cancel داخل معاملته):
     * سند الاستلام التلقائي يُلغى، وبضاعة الفاتورة بلا طلبية تخرج من المخزن.
     * يُمنع ان كانت البضاعة قد صُرفت (StockLedgerService::reverse).
     */
    public function cancelPurchaseInvoice(PurchaseInvoice $invoice, string $date): void
    {
        $receipts = app(GoodsReceiptService::class);

        // حركات الفاتورة نفسها (ومنها التسوية) اولاً: تسوية نقصت الرصيد تعود قبل عكس الاستلام.
        app(StockLedgerService::class)->reverse($invoice, $date, $invoice->reference_no);
        $this->voidInventoryEntries($invoice, $date);

        GoodsReceipt::query()
            ->where('purchase_invoice_id', $invoice->id)
            ->where('status', InventoryDocumentStatus::POSTED->value())
            ->get()
            ->each(fn (GoodsReceipt $receipt) => $receipts->cancelForInvoice($receipt, $date));
    }

    /**
     * الغاء فاتورة مبيعات مرحّلة: سند التسليم التلقائي يُلغى، وبضاعة الفاتورة بلا طلبية
     * تعود الى المخزن ويُعكس قيد تكلفتها.
     */
    public function cancelSalesInvoice(SalesInvoice $invoice, string $date): void
    {
        $notes = app(DeliveryNoteService::class);

        DeliveryNote::query()
            ->where('sales_invoice_id', $invoice->id)
            ->where('status', InventoryDocumentStatus::POSTED->value())
            ->get()
            ->each(fn (DeliveryNote $note) => $notes->cancelForInvoice($note, $date));

        app(StockLedgerService::class)->reverse($invoice, $date, $invoice->reference_no);
        $this->voidInventoryEntries($invoice, $date);
    }

    /**
     * قيود المخزون التي انشأتها الفاتورة نفسها (تكلفة فاتورة بلا طلبية، او تسوية).
     */
    private function voidInventoryEntries(PurchaseInvoice|SalesInvoice $invoice, string $date): void
    {
        JournalEntry::query()
            ->where('sourceable_type', $invoice::class)
            ->where('sourceable_id', $invoice->id)
            ->where('source_module', InventoryAccounts::SOURCE_MODULE)
            ->where('status', JournalEntryStatus::POSTED->value())
            ->get()
            ->each(fn (JournalEntry $entry) => app(JournalEntryService::class)->void($entry, __('Cancellation of').' '.$invoice->reference_no, $date));
    }

    /**
     * يسوّي الصنف الرئيسي لكل سطر وزن من طلبية على ما استلمته سندات الطلبية:
     * الفرق بالكغم يدخل او يخرج، وما بقي من فرق القيمة يعيد تقييم الرصيد. مبلغ التسوية يقابله
     * «فروقات اسعار المشتريات» الذي قيّدته الفاتورة؛ وما لم يُطبَّق (بيع قبل الفوترة) يبقى فيه.
     */
    private function trueUpPurchaseInvoice(PurchaseInvoice $invoice, PurchaseOrder $order): void
    {
        $receiptIds = GoodsReceipt::query()->where('purchase_order_id', $order->id)->where('status', InventoryDocumentStatus::POSTED->value())->pluck('id');
        $rate = (float) $invoice->exchange_rate ?: 1;
        $factor = $this->discountFactor($invoice);
        $ledger = app(StockLedgerService::class);
        $date = $invoice->invoice_date->toDateString();
        $byWarehouse = [];

        foreach ($this->orderWeightLines($invoice, 'purchase_order_item_id') as $item) {
            $lineIds = GoodsReceiptItem::query()->whereIn('goods_receipt_id', $receiptIds)->where('purchase_order_item_id', $item->purchase_order_item_id)->pluck('id');
            $movements = $this->mainMovements((int) $item->product_id, (new GoodsReceipt)->getMorphClass(), $receiptIds->all(), $lineIds->all(), StockMovementType::RECEIPT);
            $receivedKg = round((float) $movements->sum('quantity'), 4);
            $receivedValue = round((float) $movements->sum('total_cost'), 4);
            $warehouseId = (int) ($movements->last()?->warehouse_id ?: ($invoice->warehouse_id ?: $order->warehouse_id));
            $invoicedKg = round((float) $item->quantity, 4);
            $invoicedValue = round((float) $item->line_total * $factor * $rate, 4);
            $difference = round($invoicedKg - $receivedKg, 4);
            $common = $this->trueUpMovement($invoice, $item, (int) $item->product_id, $warehouseId, $date);
            $change = 0.0;

            if ($difference > 0) {
                $total = round($difference * $invoicedValue / $invoicedKg, 4);
                $ledger->receive(array_merge($common, [
                    'location_id' => app(WarehouseLayoutService::class)->primaryLocationId((int) $item->product_id, $warehouseId),
                    'quantity' => $difference,
                    'weight' => $difference,
                    'unit_cost' => round($total / $difference, 6),
                    'total_cost' => $total,
                ]));
                $change = $total;
            } elseif ($difference < 0) {
                $change = (float) $ledger->issue(array_merge($common, ['quantity' => -$difference, 'weight' => -$difference]))->sum('total_cost');
            }

            $residual = round($invoicedValue - $receivedValue - $change, 4);

            if ($residual != 0.0) {
                $change += $ledger->revalue(array_merge($common, ['amount' => $residual, 'movements' => $movements->pluck('id')->all()]));
            }

            $byWarehouse[$warehouseId] = round(($byWarehouse[$warehouseId] ?? 0) + $change, 4);
        }

        $this->postTrueUpJournal($invoice, 'PurchaseInvoice', $date, $byWarehouse, InventoryAccounts::PRICE_VARIANCE_KEY);
    }

    /**
     * يسوّي الصنف الرئيسي لكل سطر وزن من طلبية بيع على ما سلّمته سندات الطلبية: الزيادة تخرج
     * بكلفتها والنقص يعود بكلفة خروجه، ومبلغها يقابله «تكلفة البضاعة المباعة».
     */
    private function trueUpSalesInvoice(SalesInvoice $invoice, SalesOrder $order): void
    {
        $noteIds = DeliveryNote::query()->where('sales_order_id', $order->id)->where('status', InventoryDocumentStatus::POSTED->value())->pluck('id');
        $ledger = app(StockLedgerService::class);
        $date = $invoice->invoice_date->toDateString();
        $byWarehouse = [];

        foreach ($this->orderWeightLines($invoice, 'sales_order_item_id') as $item) {
            $lineIds = DeliveryNoteItem::query()->whereIn('delivery_note_id', $noteIds)->where('sales_order_item_id', $item->sales_order_item_id)->pluck('id');
            $movements = $this->mainMovements((int) $item->product_id, (new DeliveryNote)->getMorphClass(), $noteIds->all(), $lineIds->all(), StockMovementType::DELIVERY);
            $deliveredKg = round(-(float) $movements->sum('quantity'), 4);
            $deliveredCost = round(-(float) $movements->sum('total_cost'), 4);
            $warehouseId = (int) ($movements->last()?->warehouse_id ?: ($invoice->warehouse_id ?: $order->warehouse_id));
            $difference = round((float) $item->quantity - $deliveredKg, 4);
            $common = $this->trueUpMovement($invoice, $item, (int) $item->product_id, $warehouseId, $date);

            if ($difference > 0) {
                $change = (float) $ledger->issue(array_merge($common, ['quantity' => $difference, 'weight' => $difference]))->sum('total_cost');
            } elseif ($difference < 0) {
                $total = round(-$difference * $deliveredCost / $deliveredKg, 4);
                $ledger->receive(array_merge($common, [
                    'location_id' => app(WarehouseLayoutService::class)->primaryLocationId((int) $item->product_id, $warehouseId),
                    'quantity' => -$difference,
                    'weight' => -$difference,
                    'unit_cost' => round($total / -$difference, 6),
                    'total_cost' => $total,
                ]));
                $change = $total;
            } else {
                continue;
            }

            $byWarehouse[$warehouseId] = round(($byWarehouse[$warehouseId] ?? 0) + $change, 4);
        }

        $this->postTrueUpJournal($invoice, 'SalesInvoice', $date, $byWarehouse, InventoryAccounts::COGS_KEY);
    }

    /**
     * سطور الفاتورة التي جاءت من طلبية وهي بقطع: رصيد صنفها الرئيسي يتبع وزنها الفعلي.
     */
    private function orderWeightLines(PurchaseInvoice|SalesInvoice $invoice, string $orderItemKey): Collection
    {
        $invoice->loadMissing(['items.product', 'items.pieces']);

        return $invoice->items->filter(fn ($item) => filled($item->{$orderItemKey}) && $item->pieces->isNotEmpty())->values();
    }

    /**
     * حركات الصنف الرئيسي لسطر طلبية من سنداته المرحّلة.
     *
     * @param  array<int, int>  $documentIds
     * @param  array<int, int>  $lineIds
     */
    private function mainMovements(int $productId, string $morphClass, array $documentIds, array $lineIds, StockMovementType $type): Collection
    {
        return StockMovement::query()
            ->where('product_id', $productId)
            ->where('type', $type->value)
            ->where('sourceable_type', $morphClass)
            ->whereIn('sourceable_id', $documentIds)
            ->whereIn('source_line_id', $lineIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function trueUpMovement(PurchaseInvoice|SalesInvoice $invoice, $item, int $productId, int $warehouseId, string $date): array
    {
        return [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'type' => StockMovementType::INVOICE_ADJUSTMENT,
            'date' => $date,
            'source' => $invoice,
            'source_line_id' => (int) $item->id,
            'reference_no' => $invoice->reference_no,
            'description' => __('Invoice Adjustment').' '.$invoice->reference_no,
        ];
    }

    /**
     * @param  array<int, float>  $byWarehouse  تغير قيمة مخزون كل مخزن: موجب زيادة في الشراء، وخروج في البيع
     */
    private function postTrueUpJournal(PurchaseInvoice|SalesInvoice $invoice, string $sourceType, string $date, array $byWarehouse, string $offsetKey): void
    {
        $accounts = app(InventoryAccounts::class);
        $lines = [];

        foreach ($byWarehouse as $warehouseId => $change) {
            // الحركة الخارجة تسجل قيمتها سالبة، فالمبلغ بإشارته تغيّر المخزون نفسه في الحالتين.
            $inventory = round($change, 4);

            if ($inventory == 0.0) {
                continue;
            }

            $lines[] = ['account_id' => $accounts->warehouseAccountId((int) $warehouseId), 'debit' => max($inventory, 0), 'credit' => max(-$inventory, 0)];
            $lines[] = ['account_id' => $accounts->mapped($offsetKey), 'debit' => max(-$inventory, 0), 'credit' => max($inventory, 0)];
        }

        if ($lines !== []) {
            $this->postInventoryJournal($invoice, $sourceType, $date, __('Invoice Adjustment').' '.$invoice->reference_no, $lines);
        }
    }

    /**
     * @return array<int, float> سطر الطلبية => الكغم المفوتر، لسند التسليم التلقائي مع الفاتورة
     */
    private function invoicedKg(SalesInvoice $invoice): array
    {
        return $this->orderWeightLines($invoice, 'sales_order_item_id')
            ->mapWithKeys(fn ($item) => [(int) $item->sales_order_item_id => round((float) $item->quantity, 4)])
            ->all();
    }

    private function discountFactor(PurchaseInvoice|SalesInvoice $invoice): float
    {
        $linesTotal = (float) $invoice->items->sum('line_total');

        return $linesTotal > 0 ? max(($linesTotal - (float) $invoice->invoice_discount) / $linesTotal, 0) : 1;
    }

    /**
     * سطور فاتورة الشراء بلا طلبية المحمّلة على مخزون المستودع (بعملة الفاتورة).
     * تستعملها PurchaseInvoiceStockPosting لقيد الفاتورة قبل ادخال البضاعة.
     *
     * @return array<int, array<string, mixed>>
     */
    public function purchaseDebitLines(PurchaseInvoice $invoice, float $purchaseAmount, array $base): array
    {
        $lines = $this->stockLines($invoice);

        if ($lines === []) {
            return [];
        }

        $amount = min(round(array_sum(array_column($lines, 'amount')), 4), $purchaseAmount);

        // سطر وزن بلا وزن فعلي بلا مبلغ: لا شيء يُحمَّل على المخزون.
        if ($amount <= 0) {
            return [];
        }

        return [array_merge($base, [
            'account_id' => app(InventoryAccounts::class)->warehouseAccountId($this->stockWarehouseId($invoice)),
            'debit' => $amount,
            'covers' => $amount,
            'description' => __('Purchase invoice').' '.$invoice->reference_no,
        ])];
    }

    /**
     * سطور الفاتورة التي تحرّك المخزون: الصنف، الكمية، الوزن، وصافي قيمة السطر بعد خصم
     * الفاتورة العام وقبل الضريبة (بعملة الفاتورة). الخدمات لا تحرّك المخزون. سطر الوزن يعطي
     * صف الصنف الرئيسي (الكغم الفعلي بكل القيمة) وصف كل مقطع (عدده بقيمة صفر).
     *
     * @return array<int, array{item_id:int, product_id:int, quantity:float, weight:float, amount:float}>
     */
    public function stockLines(PurchaseInvoice|SalesInvoice $invoice): array
    {
        $invoice->loadMissing(['items.product.translations', 'items.pieces']);
        $factor = $this->discountFactor($invoice);
        $ledger = app(StockLedgerService::class);
        $lines = [];

        foreach ($invoice->items as $item) {
            $product = $item->product;

            if (! $product || $product->isService() || ($item->line_type ?? null) === ItemType::SERVICE->value) {
                continue;
            }

            // السطر بلا وزن فعلي كميته ومبلغه صفر: لا حركة لصنفه الرئيسي، وقطعه تتحرك كغيرها.
            $pieces = $item->pieces->filter(fn ($piece) => $piece->product_id && (float) $piece->piece_count > 0)->values();

            if ($pieces->isEmpty() && (float) $item->quantity <= 0) {
                continue;
            }

            $amount = round((float) $item->line_total * $factor, 4);

            if ($pieces->isNotEmpty()) {
                if ((float) $item->quantity > 0) {
                    $lines[] = [
                        'item_id' => (int) $item->id,
                        'product_id' => (int) $product->id,
                        'quantity' => round((float) $item->quantity, 4),
                        'weight' => round((float) $item->quantity, 4),
                        'amount' => $amount,
                    ];
                }

                foreach ($pieces as $piece) {
                    $lines[] = [
                        'item_id' => (int) $item->id,
                        'product_id' => (int) $piece->product_id,
                        'quantity' => round((float) $piece->piece_count, 4),
                        'weight' => round((float) $piece->total_weight, 4),
                        'amount' => 0.0,
                    ];
                }

                continue;
            }

            // صنف رئيسي بلا قطع: كميته الكغم الفعلي، ولا صف غيره.
            if ($ledger->isMainItem($product)) {
                $lines[] = [
                    'item_id' => (int) $item->id,
                    'product_id' => (int) $product->id,
                    'quantity' => round((float) $item->quantity, 4),
                    'weight' => round((float) $item->quantity, 4),
                    'amount' => $amount,
                ];

                continue;
            }

            [$quantity, $weight] = $this->stockQuantity($product, (float) $item->quantity);

            // مقطع مفوتر مباشرة بالكغم: الوزن والقيمة على صنفه الرئيسي، والعدد عليه.
            if ($product->pricing_type === 'weight' && filled($product->main_product_id)) {
                $lines[] = [
                    'item_id' => (int) $item->id,
                    'product_id' => (int) $product->main_product_id,
                    'quantity' => round((float) $item->quantity, 4),
                    'weight' => round((float) $item->quantity, 4),
                    'amount' => $amount,
                ];
                $lines[] = ['item_id' => (int) $item->id, 'product_id' => (int) $product->id, 'quantity' => $quantity, 'weight' => $weight, 'amount' => 0.0];

                continue;
            }

            $lines[] = [
                'item_id' => (int) $item->id,
                'product_id' => (int) $product->id,
                'quantity' => $quantity,
                'weight' => $weight,
                'amount' => $amount,
            ];
        }

        return $lines;
    }

    /**
     * مخزن حركة الفاتورة بلا طلبية: مخزن الفاتورة، او المخزن الوحيد ان لم يكن غيره
     * (ويُحفظ على الفاتورة)؛ والا يُطلب اختياره.
     */
    private function stockWarehouseId(PurchaseInvoice|SalesInvoice $invoice): int
    {
        $warehouseId = $this->resolveWarehouseId($invoice->warehouse_id);

        if (! $warehouseId) {
            throw new DomainException($invoice instanceof PurchaseInvoice
                ? __('Select the warehouse that receives the items of this invoice.')
                : __('Select the warehouse that the items of this invoice are issued from.'));
        }

        if ((int) $invoice->warehouse_id !== $warehouseId) {
            $invoice->update(['warehouse_id' => $warehouseId]);
        }

        return $warehouseId;
    }

    private function resolveWarehouseId(mixed $warehouseId): ?int
    {
        if ($warehouseId) {
            return (int) $warehouseId;
        }

        $active = Warehouse::query()->where('is_active', true)->limit(2)->pluck('id');

        return $active->count() === 1 ? (int) $active->first() : null;
    }

    /**
     * @return array{0: float, 1: float} عدد القطع والوزن
     */
    private function stockQuantity(Product $product, float $invoiced): array
    {
        $perPiece = $this->weightPerUnit($product);

        if ($product->pricing_type !== 'weight') {
            return [round($invoiced, 4), round($invoiced * $perPiece, 4)];
        }

        if ($perPiece <= 0) {
            throw new DomainException(__(':item is priced by weight; set its piece length and weight per meter so the weight can be converted to pieces.', [
                'item' => $this->label($product),
            ]));
        }

        return [round($invoiced / $perPiece, 4), round($invoiced, 4)];
    }

    private function label(Product $product): string
    {
        return trim(($product->barcode ? $product->barcode.' - ' : '').$product->name);
    }
}
