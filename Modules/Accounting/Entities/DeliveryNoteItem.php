<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Entities\Concerns\IsInventoryDocumentLine;

/**
 * سطر تسليم. location_id اختياري: بلا موقع يسحب النظام من الموقع الاساسي ثم الاضافية،
 * وما سُحب من كل موقع يُحفظ في picked_locations ليُطبع في قائمة التحضير.
 */
class DeliveryNoteItem extends Model
{
    use IsInventoryDocumentLine;

    protected $fillable = [
        'delivery_note_id',
        'sales_order_item_id',
        'sales_order_item_piece_id',
        'product_id',
        'location_id',
        'picked_locations',
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

    protected $casts = [
        'picked_locations' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'delivery_note_items');
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class, 'delivery_note_id');
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }

    public function salesOrderItemPiece(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItemPiece::class, 'sales_order_item_piece_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }
}
