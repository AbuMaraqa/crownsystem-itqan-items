<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صنف فرعي (قطع) داخل سطر صنف وزن في فاتورة الشراء. من طلبية يحمل قطعتها المقابلة.
 */
class PurchaseInvoiceItemPiece extends DocumentItemPiece
{
    protected const TABLE = 'purchase_invoice_item_pieces';

    protected const COLUMNS = ['purchase_invoice_item_id', 'purchase_order_item_piece_id'];

    public function purchaseInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceItem::class, 'purchase_invoice_item_id');
    }

    public function purchaseOrderItemPiece(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItemPiece::class, 'purchase_order_item_piece_id');
    }
}
