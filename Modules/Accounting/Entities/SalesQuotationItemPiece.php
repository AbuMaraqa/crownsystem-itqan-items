<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صنف فرعي (قطع) داخل سطر صنف وزن في عرض سعر البيع؛ ينتقل الى طلبية البيع عند تحويله.
 */
class SalesQuotationItemPiece extends DocumentItemPiece
{
    protected const TABLE = 'sales_quotation_item_pieces';

    protected const COLUMNS = ['sales_quotation_item_id'];

    public function salesQuotationItem(): BelongsTo
    {
        return $this->belongsTo(SalesQuotationItem::class, 'sales_quotation_item_id');
    }
}
