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

class PurchaseInvoiceItem extends Model
{
    use HasFactory;
    use HasPriceGroup;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'purchase_invoice_id',
        'purchase_order_item_id',
        'product_id',
        'barcode',
        'description',
        'quantity',
        'total_weight',
        'actual_weight',
        'unit_price',
        'discount_rate',
        'discount_amount',
        'line_total',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'total_weight' => 'decimal:4',
        'actual_weight' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount_rate' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'line_total' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'purchase_invoice_items');
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

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** الاصناف الفرعية لسطر صنف الوزن. */
    public function pieces(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItemPiece::class, 'purchase_invoice_item_id')->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
