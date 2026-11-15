<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Entities\Concerns\IsInventoryDocumentLine;

/**
 * quantity موجبة = زيادة، سالبة = نقص. في الجرد تُحسب = العدد الفعلي − رصيد النظام عند الترحيل.
 */
class StockAdjustmentItem extends Model
{
    use IsInventoryDocumentLine;

    protected $fillable = [
        'stock_adjustment_id',
        'product_id',
        'location_id',
        'system_quantity',
        'counted_quantity',
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
        'system_quantity' => 'decimal:4',
        'counted_quantity' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'stock_adjustment_items');
    }

    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }
}
