<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseOrderItem extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'purchase_order_id',
        'source_purchase_order_item_id',
        'product_id',
        'category_id',
        'barcode',
        'description',
        'quantity',
        'length',
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
        'length' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'purchase_order_items');
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * كمية البند عند فوترته: الصنف الموزون سعره سعر الكغم، فيُفوتر بوزنه (الفعلي إن أُدخل
     * وإلا الكلي) لا بكميته، لان بند الفاتورة يُحسب كمية × سعر.
     */
    public function invoiceQuantity(): float
    {
        $isWeightPriced = (float) $this->total_weight > 0 || $this->product?->pricing_type === 'weight';
        $weight = (float) $this->actual_weight > 0 ? (float) $this->actual_weight : (float) $this->total_weight;

        return $isWeightPriced && $weight > 0 ? $weight : (float) $this->quantity;
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(PurchaseOrderItemPiece::class, 'purchase_order_item_id')->orderBy('sort_order');
    }

    /**
     * The item on another purchase order that this item was consolidated from.
     */
    public function sourcePurchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_purchase_order_item_id');
    }

    /**
     * Consolidated purchase order items that were pulled from this item.
     */
    public function consolidatedIntoItems(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderItem::class, 'source_purchase_order_item_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
