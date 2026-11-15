<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صنف فرعي (قطع) مرتجع للمورد من سطر صنف وزن في فاتورة الشراء، بعدد قطع لا يتجاوز ما فوتر.
 */
class PurchaseReturnItemPiece extends DocumentItemPiece
{
    protected const TABLE = 'purchase_return_item_pieces';

    protected const COLUMNS = ['purchase_return_item_id', 'purchase_invoice_item_piece_id', 'unit_cost'];

    public function purchaseReturnItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturnItem::class, 'purchase_return_item_id');
    }

    public function purchaseInvoiceItemPiece(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceItemPiece::class, 'purchase_invoice_item_piece_id');
    }
}
