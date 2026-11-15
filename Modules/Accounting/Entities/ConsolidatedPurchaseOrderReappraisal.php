<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Enums\ReappraisalStatus;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ConsolidatedPurchaseOrderReappraisal extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'consolidated_purchase_order_id',
        'reappraisal_date',
        'authority',
        'reference_no',
        'declared_value',
        'reappraised_value',
        'currency_id',
        'status',
        'reason',
        'attachment_path',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'reappraisal_date' => 'date',
        'declared_value' => 'decimal:4',
        'reappraised_value' => 'decimal:4',
        'status' => ReappraisalStatus::class,
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'consolidated_purchase_order_reappraisals');
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

    /**
     * فرق التخمين: موجب اذا رفعت الجمارك القيمة عن المصرّح بها.
     * محسوب دائماً من القيمتين حتى لا يتعارض مع اي تعديل لاحق.
     */
    public function getDifferenceAttribute(): float
    {
        return round((float) $this->reappraised_value - (float) $this->declared_value, 4);
    }

    public function consolidatedPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrder::class, 'consolidated_purchase_order_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * بنود اعادة التخمين وقيمة كل بند.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderReappraisalLine::class, 'reappraisal_id');
    }

    /**
     * اصناف الطلبية المتأثرة باعادة التخمين، وملاحظة كل صنف.
     */
    public function items(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderReappraisalItem::class, 'reappraisal_id');
    }
}
