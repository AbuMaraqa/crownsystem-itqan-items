<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صنف مشمول بتكلفة اضافية، مع ملاحظة تخص هذا الصنف وحده.
 */
class ConsolidatedPurchaseOrderAdditionalCostItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'additional_cost_id',
        'consolidated_purchase_order_item_id',
        'notes',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'consolidated_purchase_order_additional_cost_items');
    }

    public function additionalCost(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrderAdditionalCost::class, 'additional_cost_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrderItem::class, 'consolidated_purchase_order_item_id');
    }
}
