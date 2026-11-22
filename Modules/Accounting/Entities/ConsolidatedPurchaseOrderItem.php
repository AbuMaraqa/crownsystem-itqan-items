<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Entities\Concerns\HasPriceGroup;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ConsolidatedPurchaseOrderItem extends Model
{
    use HasPriceGroup;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'consolidated_purchase_order_id',
        'source_purchase_order_item_id',
        'product_id',
        'barcode',
        'description',
        'quantity',
        'unit_price',
        'discount_rate',
        'discount_amount',
        'line_total',
        'total_weight',
        'actual_weight',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount_rate' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'line_total' => 'decimal:4',
        'total_weight' => 'decimal:4',
        'actual_weight' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'consolidated_purchase_order_items');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->getFillable())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "This model has been {$eventName} and value ")
            ->useLogName(class_basename($this));
    }

    public function consolidatedPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrder::class, 'consolidated_purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderItemPiece::class, 'consolidated_purchase_order_item_id')->orderBy('sort_order');
    }

    /**
     * The original purchase order item this item was pulled from.
     */
    public function sourcePurchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'source_purchase_order_item_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
