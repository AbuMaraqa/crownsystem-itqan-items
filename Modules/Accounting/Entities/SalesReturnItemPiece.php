<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صنف فرعي (قطع) مرتجع من سطر صنف وزن في فاتورة البيع، بعدد قطع لا يتجاوز ما فوتر.
 */
class SalesReturnItemPiece extends DocumentItemPiece
{
    protected const TABLE = 'sales_return_item_pieces';

    protected const COLUMNS = ['sales_return_item_id', 'sales_invoice_item_piece_id', 'unit_cost'];

    public function salesReturnItem(): BelongsTo
    {
        return $this->belongsTo(SalesReturnItem::class, 'sales_return_item_id');
    }

    public function salesInvoiceItemPiece(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceItemPiece::class, 'sales_invoice_item_piece_id');
    }
}
