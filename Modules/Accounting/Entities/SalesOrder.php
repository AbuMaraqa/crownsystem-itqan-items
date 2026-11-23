<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Accounting\Enums\SalesOrderFulfillmentType;
use Modules\Accounting\Enums\SalesOrderStatus;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SalesOrder extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'sales_quotation_id',
        'client_id',
        'branch_id',
        'warehouse_id',
        'fulfillment_type',
        'direct_supplier_id',
        'order_type_id',
        'purchasing_notified_at',
        'reference_no',
        'status',
        'order_date',
        'expected_delivery_date',
        'ready_by_date',
        'currency_id',
        'tax_id',
        'exchange_rate',
        'sub_total',
        'items_discount',
        'order_discount',
        'tax_rate',
        'tax_amount',
        'total_amount',
        'confirmed_at',
        'prepared_at',
        'invoiced_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'ready_by_date' => 'date',
        'status' => SalesOrderStatus::class,
        'fulfillment_type' => SalesOrderFulfillmentType::class,
        'purchasing_notified_at' => 'datetime',
        'exchange_rate' => 'decimal:8',
        'sub_total' => 'decimal:4',
        'items_discount' => 'decimal:4',
        'order_discount' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'confirmed_at' => 'datetime',
        'prepared_at' => 'datetime',
        'invoiced_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'sales_orders');
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
        return $this->hasMany(SalesOrderItem::class, 'sales_order_id');
    }

    /**
     * مواد العميل المستلمة لتقديم الخدمة عليها؛ ملك العميل فلا قيمة لها في الطلبية.
     */
    public function customerMaterials(): HasMany
    {
        return $this->hasMany(SalesOrderCustomerMaterial::class, 'sales_order_id')->orderBy('sort_order');
    }

    public function salesInvoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'sales_order_id');
    }

    public function activeSalesInvoices(): HasMany
    {
        return $this->salesInvoices()->where('status', '!=', SalesInvoiceStatus::CANCELLED->value());
    }

    /**
     * أوامر الإنتاج التي تنفذ الطلبية في قسم الإنتاج.
     */
    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class, 'sales_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * المورد المقترح لتوريد الطلبية مباشرة للزبون.
     */
    public function directSupplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'direct_supplier_id');
    }

    /**
     * طلبيات الشراء «شحن مباشر للزبون» التي انشأها قسم المشتريات لهذه الطلبية.
     */
    public function directPurchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'direct_sales_order_id');
    }

    /**
     * نوع الطلبية (SalesOrderType) ومنه مهلة جاهزيتها. النوع المحذوف يبقى ظاهراً على طلبياته.
     */
    public function orderType(): BelongsTo
    {
        return $this->belongsTo(SalesOrderType::class, 'order_type_id')->withTrashed();
    }

    /**
     * متأخرة: مضى موعد جاهزيتها ولم تُفوتر بعد (مؤكدة او قيد التجهيز).
     */
    public function isLate(): bool
    {
        return $this->ready_by_date !== null
            && in_array($this->status, self::OPEN_STATUSES, true)
            && $this->ready_by_date->lt(today());
    }

    /**
     * الحالات التي ينتظر فيها موعد الجاهزية: بعد التأكيد وقبل الفوترة.
     */
    public const OPEN_STATUSES = [SalesOrderStatus::CONFIRMED, SalesOrderStatus::PREPARING];

    public function isDirectSupply(): bool
    {
        return $this->fulfillment_type === SalesOrderFulfillmentType::DIRECT;
    }

    public function hasDirectPurchaseOrder(): bool
    {
        return $this->directPurchaseOrders()->where('status', '!=', PurchaseOrderStatus::CANCELLED->value())->exists();
    }

    /**
     * طلبية توريد مباشر مؤكدة لم تُنشأ لها بعد طلبية شراء قائمة.
     */
    public function awaitsPurchaseOrder(): bool
    {
        return $this->isDirectSupply()
            && ! in_array($this->status, [SalesOrderStatus::DRAFT, SalesOrderStatus::CANCELLED], true)
            && ! $this->hasDirectPurchaseOrder();
    }

    public function canBeEdited(): bool
    {
        return ! in_array($this->status, [SalesOrderStatus::INVOICED, SalesOrderStatus::CANCELLED], true);
    }

    public function canBeDeleted(): bool
    {
        $hasInvoices = $this->relationLoaded('salesInvoices')
            ? $this->salesInvoices->isNotEmpty()
            : $this->salesInvoices()->exists();

        return $this->status === SalesOrderStatus::DRAFT && ! $hasInvoices;
    }

    public function canCreateInvoice(): bool
    {
        if ($this->status === SalesOrderStatus::CANCELLED) {
            return false;
        }

        $hasInvoices = $this->relationLoaded('salesInvoices')
            ? $this->salesInvoices->isNotEmpty()
            : $this->salesInvoices()->exists();

        return ! $hasInvoices;
    }
}
