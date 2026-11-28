<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\PurchaseQuotationStatus;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Concerns\HasSearchIndex;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * عرض سعر الشراء: سعر المورد لسطور طلبية شراء واحدة. لكل طلبية عرض فعّال واحد؛
 * يُسعَّر وهو مسودة، ثم يُعتمد فتصبح اسعاره ملزمة، ومنه تُنشأ فاتورة الشراء.
 */
class PurchaseQuotation extends Model
{
    use HasFactory;
    use HasSearchIndex;
    use LogsActivity;
    use SoftDeletes;

    /**
     * البحث في عروض اسعار الشراء: بالرقم المرجعي ورقم المورد والملاحظات.
     *
     * @return list<string>
     */
    public static function searchColumns(): array
    {
        return ['reference_no', 'supplier_reference', 'notes'];
    }

    protected $fillable = [
        'purchase_order_id',
        'client_id',
        'branch_id',
        'reference_no',
        'supplier_reference',
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
        'approved_at',
        'approved_by',
        'cancelled_at',
        'cancelled_by',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'status' => PurchaseQuotationStatus::class,
        'exchange_rate' => 'decimal:8',
        'sub_total' => 'decimal:4',
        'items_discount' => 'decimal:4',
        'quotation_discount' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'purchase_quotations');
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
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
        return $this->hasMany(PurchaseQuotationItem::class, 'purchase_quotation_id');
    }

    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'purchase_quotation_id');
    }

    /**
     * فواتير العرض غير الملغاة.
     */
    public function activePurchaseInvoices(): HasMany
    {
        return $this->purchaseInvoices()->where('status', '!=', PurchaseInvoiceStatus::CANCELLED->value());
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * العرض الفعّال (غير الملغى) هو الذي يمنع انشاء عرض آخر للطلبية نفسها.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', PurchaseQuotationStatus::CANCELLED->value);
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseQuotationStatus::DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === PurchaseQuotationStatus::APPROVED;
    }

    public function canBeEdited(): bool
    {
        return $this->isDraft();
    }

    public function canBeDeleted(): bool
    {
        return $this->isDraft();
    }

    public function canBeApproved(): bool
    {
        return $this->isDraft();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [PurchaseQuotationStatus::DRAFT, PurchaseQuotationStatus::APPROVED], true);
    }

    public function canCreateInvoice(): bool
    {
        return $this->isApproved();
    }
}
