<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Enums\ProductionOrderStatus;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * أمر إنتاج لتنفيذ طلبية بيع. التكلفة الكلية = الاصناف + الأجور + الخدمات،
 * وتكلفة الوحدة = التكلفة الكلية ÷ الكمية المنتجة؛ تُحسب عند كل حفظ.
 */
class ProductionOrder extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'sales_order_id',
        'client_id',
        'branch_id',
        'warehouse_id',
        'currency_id',
        'reference_no',
        'status',
        'order_date',
        'due_date',
        'produced_quantity',
        'materials_cost',
        'labor_cost',
        'services_cost',
        'total_cost',
        'unit_cost',
        'sales_amount',
        'started_at',
        'completed_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'due_date' => 'date',
        'status' => ProductionOrderStatus::class,
        'produced_quantity' => 'decimal:4',
        'materials_cost' => 'decimal:4',
        'labor_cost' => 'decimal:4',
        'services_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'sales_amount' => 'decimal:4',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'production_orders');
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ProductionOrderMaterial::class, 'production_order_id')->orderBy('sort_order');
    }

    public function labor(): HasMany
    {
        return $this->hasMany(ProductionOrderLabor::class, 'production_order_id')->orderBy('sort_order');
    }

    public function services(): HasMany
    {
        return $this->hasMany(ProductionOrderServiceLine::class, 'production_order_id')->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * هامش الربح = قيمة البيع (صافية من الضريبة) - التكلفة الكلية.
     */
    public function margin(): float
    {
        return round((float) $this->sales_amount - (float) $this->total_cost, 4);
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, [ProductionOrderStatus::DRAFT, ProductionOrderStatus::IN_PROGRESS], true);
    }

    public function canBeDeleted(): bool
    {
        return $this->status === ProductionOrderStatus::DRAFT;
    }

    public function canStart(): bool
    {
        return $this->status === ProductionOrderStatus::DRAFT;
    }

    public function canComplete(): bool
    {
        return in_array($this->status, [ProductionOrderStatus::DRAFT, ProductionOrderStatus::IN_PROGRESS], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [ProductionOrderStatus::DRAFT, ProductionOrderStatus::IN_PROGRESS], true);
    }

    public function canBeReopened(): bool
    {
        return $this->status === ProductionOrderStatus::COMPLETED;
    }
}
