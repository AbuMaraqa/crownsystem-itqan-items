<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Entities\Concerns\IsInventoryDocumentLine;

class GoodsReceiptItem extends Model
{
    use IsInventoryDocumentLine;

    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_item_id',
        'purchase_order_item_piece_id',
        'product_id',
        'location_id',
        'barcode',
        'description',
        'quantity',
        'piece_length',
        'weight',
        'unit_cost',
        'total_cost',
        'notes',
        'sort_order',
        'created_by',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'goods_receipt_items');
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function purchaseOrderItemPiece(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItemPiece::class, 'purchase_order_item_piece_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }
}
