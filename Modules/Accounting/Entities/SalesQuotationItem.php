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

class SalesQuotationItem extends Model
{
    use HasFactory;
    use HasPriceGroup;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'sales_quotation_id',
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

        $this->setTable(config('accounting.table_prefix').'sales_quotation_items');
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

    public function salesQuotation(): BelongsTo
    {
        return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * الاصناف الفرعية (قطع) لسطر صنف الوزن.
     */
    public function pieces(): HasMany
    {
        return $this->hasMany(SalesQuotationItemPiece::class, 'sales_quotation_item_id')->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
