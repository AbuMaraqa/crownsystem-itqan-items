<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صنف من اصناف الطلبية متأثر باعادة التخمين، مع ملاحظة تخصه وحده.
 */
class ConsolidatedPurchaseOrderReappraisalItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'reappraisal_id',
        'consolidated_purchase_order_item_id',
        'notes',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'consolidated_purchase_order_reappraisal_items');
    }

    public function reappraisal(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrderReappraisal::class, 'reappraisal_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrderItem::class, 'consolidated_purchase_order_item_id');
    }
}
