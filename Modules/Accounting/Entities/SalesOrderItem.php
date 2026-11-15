<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Entities\User;
use Modules\Items\Entities\Product;
use Modules\Items\Enums\ItemType;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SalesOrderItem extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'sales_order_id',
        'sales_quotation_item_id',
        'product_id',
        'line_type',
        'pricing_basis',
        'barcode',
        'description',
        'quantity',
        'unit_price',
        'discount_rate',
        'discount_amount',
        'line_total',
        'unit_cost',
        'cost_total',
        'cost_source',
        'service_provider_id',
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
        'unit_cost' => 'decimal:4',
        'cost_total' => 'decimal:4',
        'total_weight' => 'decimal:4',
        'actual_weight' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'sales_order_items');
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

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function salesQuotationItem(): BelongsTo
    {
        return $this->belongsTo(SalesQuotationItem::class, 'sales_quotation_item_id');
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
        // كمية الخدمة هي ما يُسعَّر به مباشرة (كغم او نقلات او وحدات).
        if ($this->isService()) {
            return (float) $this->quantity;
        }

        $isWeightPriced = (float) $this->total_weight > 0 || $this->product?->pricing_type === 'weight';
        $weight = (float) $this->actual_weight > 0 ? (float) $this->actual_weight : (float) $this->total_weight;

        return $isWeightPriced && $weight > 0 ? $weight : (float) $this->quantity;
    }

    public function isService(): bool
    {
        return $this->line_type === ItemType::SERVICE->value;
    }

    /**
     * الجهة الخارجية المنفذة للخدمة (مورد او شركة نقل)، وتُقيَّد التكلفة لحسابها.
     */
    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'service_provider_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(SalesOrderItemPiece::class, 'sales_order_item_id')->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
