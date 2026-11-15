<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صنف فرعي (قطع) داخل سطر صنف وزن في فاتورة البيع. من طلبية يحمل قطعتها المقابلة.
 */
class SalesInvoiceItemPiece extends DocumentItemPiece
{
    protected const TABLE = 'sales_invoice_item_pieces';

    protected const COLUMNS = ['sales_invoice_item_id', 'sales_order_item_piece_id'];

    public function salesInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceItem::class, 'sales_invoice_item_id');
    }

    public function salesOrderItemPiece(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItemPiece::class, 'sales_order_item_piece_id');
    }
}
