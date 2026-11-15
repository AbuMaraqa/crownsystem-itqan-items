<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ConsolidatedPurchaseOrderAward extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'consolidated_purchase_order_id',
        'price_offer_id',
        'notes',
        'awarded_by',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'consolidated_purchase_order_awards');
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

    public function priceOffer(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedPurchaseOrderPriceOffer::class, 'price_offer_id');
    }

    public function awardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }
}
