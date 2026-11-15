<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Entities\Concerns\IsInventoryDocumentLine;

class StockTransferItem extends Model
{
    use IsInventoryDocumentLine;

    protected $fillable = [
        'stock_transfer_id',
        'product_id',
        'from_location_id',
        'to_location_id',
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

        $this->setTable(config('accounting.table_prefix').'stock_transfer_items');
    }

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'to_location_id');
    }
}
