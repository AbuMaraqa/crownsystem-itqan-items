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
use Modules\Items\Enums\ItemType;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SalesInvoiceItem extends Model
{
    use HasFactory;
    use HasPriceGroup;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'sales_invoice_id',
        'sales_order_item_id',
        'product_id',
        'line_type',
        'pricing_basis',
        'barcode',
        'description',
        'quantity',
        'total_weight',
        'actual_weight',
        'unit_price',
        'discount_rate',
        'discount_amount',
        'line_total',
        'unit_cost',
        'cost_total',
        'cost_source',
        'service_provider_id',
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
        'unit_cost' => 'decimal:4',
        'cost_total' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'sales_invoice_items');
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

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
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
        return $this->hasMany(SalesInvoiceItemPiece::class, 'sales_invoice_item_id')->orderBy('sort_order');
    }

    public function isService(): bool
    {
        return $this->line_type === ItemType::SERVICE->value;
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'service_provider_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
