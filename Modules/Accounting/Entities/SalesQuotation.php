<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Enums\SalesQuotationStatus;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Concerns\HasSearchIndex;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SalesQuotation extends Model
{
    use HasFactory;
    use HasSearchIndex;
    use LogsActivity;
    use SoftDeletes;

    /**
     * البحث في عروض اسعار البيع: بالرقم المرجعي والملاحظات.
     *
     * @return list<string>
     */
    public static function searchColumns(): array
    {
        return ['reference_no', 'notes'];
    }

    protected $fillable = [
        'client_id',
        'branch_id',
        'warehouse_id',
        'reference_no',
        'status',
        'quotation_date',
        'valid_until',
        'currency_id',
        'tax_id',
        'exchange_rate',
        'sub_total',
        'items_discount',
        'quotation_discount',
        'tax_rate',
        'tax_amount',
        'total_amount',
        'converted_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'status' => SalesQuotationStatus::class,
        'exchange_rate' => 'decimal:8',
        'sub_total' => 'decimal:4',
        'items_discount' => 'decimal:4',
        'quotation_discount' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'converted_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'sales_quotations');
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

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesQuotationItem::class, 'sales_quotation_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'sales_quotation_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, [SalesQuotationStatus::DRAFT, SalesQuotationStatus::APPROVED], true);
    }

    public function canBeDeleted(): bool
    {
        return $this->status === SalesQuotationStatus::DRAFT && ! $this->salesOrders()->exists();
    }

    public function canBeConverted(): bool
    {
        return in_array($this->status, [SalesQuotationStatus::DRAFT, SalesQuotationStatus::APPROVED], true)
            && ! $this->salesOrders()->exists();
    }
}
