<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsolidatedPurchaseOrderDeliveryCostItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'cost_element_id',
        'amount',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'consolidated_purchase_order_delivery_cost_items');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrderDelivery::class, 'delivery_id');
    }

    public function costElement(): BelongsTo
    {
        return $this->belongsTo(DeliveryCostElement::class, 'cost_element_id');
    }
}
