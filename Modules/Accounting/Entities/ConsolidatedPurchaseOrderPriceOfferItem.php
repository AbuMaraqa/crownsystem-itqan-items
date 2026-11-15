<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsolidatedPurchaseOrderPriceOfferItem extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'price_offer_id',
        'consolidated_purchase_order_item_id',
        'description',
        'quantity',
        'unit_price',
        'discount_rate',
        'discount_amount',
        'bonus_note',
        'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount_rate' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'line_total' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'consolidated_purchase_order_price_offer_items');
    }

    public function priceOffer(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrderPriceOffer::class, 'price_offer_id');
    }

    public function consolidatedPurchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrderItem::class, 'consolidated_purchase_order_item_id');
    }
}
