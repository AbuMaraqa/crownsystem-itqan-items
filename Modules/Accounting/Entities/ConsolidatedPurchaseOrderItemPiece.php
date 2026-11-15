<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;

class ConsolidatedPurchaseOrderItemPiece extends Model
{
    protected $fillable = [
        'consolidated_purchase_order_item_id',
        'source_purchase_order_item_piece_id',
        'product_id',
        'barcode',
        'description',
        'piece_count',
        'piece_length',
        'weight_per_meter',
        'total_weight',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'piece_count' => 'decimal:4',
        'piece_length' => 'decimal:4',
        'weight_per_meter' => 'decimal:4',
        'total_weight' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'consolidated_purchase_order_item_pieces');
    }

    public function consolidatedPurchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrderItem::class, 'consolidated_purchase_order_item_id');
    }

    /**
     * الصنف الفرعي في طلبية الشراء الذي سُحبت منه هذه القطعة.
     */
    public function sourcePiece(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItemPiece::class, 'source_purchase_order_item_piece_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
