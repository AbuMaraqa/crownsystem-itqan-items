<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Enums\ConsolidatedPurchaseOrderStatus;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ConsolidatedPurchaseOrder extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'branch_id',
        'warehouse_id',
        'reference_no',
        'status',
        'order_date',
        'expected_delivery_date',
        'currency_id',
        'tax_id',
        'exchange_rate',
        'sub_total',
        'items_discount',
        'order_discount',
        'tax_rate',
        'tax_amount',
        'total_amount',
        'notes',
        'created_by',
        'followed_up_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'status' => ConsolidatedPurchaseOrderStatus::class,
        'exchange_rate' => 'decimal:8',
        'sub_total' => 'decimal:4',
        'items_discount' => 'decimal:4',
        'order_discount' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'consolidated_purchase_orders');
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
        return $this->hasMany(ConsolidatedPurchaseOrderItem::class, 'consolidated_purchase_order_id');
    }

    public function priceOffers(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderPriceOffer::class, 'consolidated_purchase_order_id');
    }

    public function award(): HasOne
    {
        return $this->hasOne(ConsolidatedPurchaseOrderAward::class, 'consolidated_purchase_order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderPayment::class, 'consolidated_purchase_order_id');
    }

    public function bankCredits(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderBankCredit::class, 'consolidated_purchase_order_id');
    }

    public function reappraisals(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderReappraisal::class, 'consolidated_purchase_order_id');
    }

    public function additionalCosts(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderAdditionalCost::class, 'consolidated_purchase_order_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderShipment::class, 'consolidated_purchase_order_id');
    }

    public function insurances(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderInsurance::class, 'consolidated_purchase_order_id');
    }

    public function clearances(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderClearance::class, 'consolidated_purchase_order_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderDelivery::class, 'consolidated_purchase_order_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderAttachment::class, 'consolidated_purchase_order_id');
    }

    public function orderNotes(): HasMany
    {
        return $this->hasMany(ConsolidatedPurchaseOrderNote::class, 'consolidated_purchase_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function followedUpBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_up_by');
    }

    /**
     * عدد السجلات في كل تبويب من صفحة الطلبية، يظهر بجانب اسم التبويب حتى يعرف الموظف اي
     * التبويبات فيها بيانات دون فتحها. استعلام واحد لكل العدادات.
     *
     * @return array<string, int>
     */
    public function tabCounts(): array
    {
        $this->loadCount([
            'items',
            'priceOffers',
            'payments',
            'bankCredits',
            'shipments',
            'insurances',
            'clearances',
            'deliveries',
            'additionalCosts',
            'reappraisals',
            'attachments',
            'orderNotes',
        ]);

        return [
            'items' => (int) $this->items_count,
            'price_quotes' => (int) $this->price_offers_count,
            // الترسية واحدة لا اكثر، فالعداد هنا يعني "تمت" او "لم تتم".
            'awarding' => $this->award()->exists() ? 1 : 0,
            'financial_file' => (int) $this->payments_count + (int) $this->bank_credits_count,
            'shipping' => (int) $this->shipments_count,
            'insurance' => (int) $this->insurances_count,
            'clearance' => (int) $this->clearances_count,
            'additional_costs' => (int) $this->additional_costs_count,
            'reappraisal' => (int) $this->reappraisals_count,
            'delivery' => (int) $this->deliveries_count,
            'attachments' => (int) $this->attachments_count,
            'notes' => (int) $this->order_notes_count,
        ];
    }
}
