<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Enums\ShippingClassification;
use Modules\Accounting\Enums\ShippingTerms;
use Modules\Accounting\Enums\ShippingType;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ConsolidatedPurchaseOrderShipment extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'consolidated_purchase_order_id',
        'shipping_company_id',
        'shipping_type',
        'shipping_classification',
        'container_number',
        'invoice_number',
        'currency_id',
        'price',
        'net_weight',
        'gross_weight',
        'cbm',
        'shipping_terms',
        'attachment_path',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'shipping_type' => ShippingType::class,
        'shipping_classification' => ShippingClassification::class,
        'shipping_terms' => ShippingTerms::class,
        'price' => 'decimal:4',
        'net_weight' => 'decimal:4',
        'gross_weight' => 'decimal:4',
        'cbm' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'consolidated_purchase_order_shipments');
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

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipping_company_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
