<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Enums\PurchaseInvoiceStatus;
use Modules\Accounting\Enums\PurchaseOrderDeliveryType;
use Modules\Accounting\Enums\PurchaseOrderStatus;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Concerns\HasSearchIndex;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PurchaseOrder extends Model implements HasMedia
{
    use HasFactory;
    use HasSearchIndex;
    use InteractsWithMedia;
    use LogsActivity;
    use SoftDeletes;

    /**
     * البحث في اوامر الشراء: بالرقم المرجعي والملاحظات.
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
        'delivery_type',
        'direct_customer_id',
        'direct_sales_order_id',
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
        'confirmed_at',
        'received_at',
        'invoiced_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'status' => PurchaseOrderStatus::class,
        'exchange_rate' => 'decimal:8',
        'sub_total' => 'decimal:4',
        'items_discount' => 'decimal:4',
        'order_discount' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'confirmed_at' => 'datetime',
        'received_at' => 'datetime',
        'invoiced_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'purchase_orders');
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
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'purchase_order_id');
    }

    /**
     * فواتير الطلبية غير الملغاة؛ الفاتورة الملغاة لا تمنع فوترة الطلبية من جديد.
     */
    public function activePurchaseInvoices(): HasMany
    {
        return $this->purchaseInvoices()->where('status', '!=', PurchaseInvoiceStatus::CANCELLED->value());
    }

    public function purchaseQuotations(): HasMany
    {
        return $this->hasMany(PurchaseQuotation::class, 'purchase_order_id');
    }

    /**
     * عرض السعر الفعّال للطلبية (عرض واحد غير ملغى على الاكثر).
     */
    public function activePurchaseQuotation(): ?PurchaseQuotation
    {
        return $this->purchaseQuotations()->active()->latest('id')->first();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'purchase_order_id');
    }

    /**
     * الزبون الذي يشحن له المورد مباشرة (حين لا تدخل البضاعة المخزن).
     */
    public function directCustomer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'direct_customer_id');
    }

    public function directSalesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'direct_sales_order_id');
    }

    public function isDirectShipment(): bool
    {
        return $this->delivery_type === PurchaseOrderDeliveryType::DIRECT->value;
    }

    public function canBeEdited(): bool
    {
        return ! in_array($this->status, [PurchaseOrderStatus::INVOICED, PurchaseOrderStatus::CANCELLED], true);
    }

    public function canBeDeleted(): bool
    {
        $hasInvoices = $this->relationLoaded('purchaseInvoices')
            ? $this->purchaseInvoices->isNotEmpty()
            : $this->purchaseInvoices()->exists();

        return $this->status === PurchaseOrderStatus::DRAFT
            && ! $hasInvoices
            && ! $this->purchaseQuotations()->active()->exists();
    }

    public function canCreateInvoice(): bool
    {
        if ($this->status === PurchaseOrderStatus::CANCELLED) {
            return false;
        }

        $hasInvoices = $this->relationLoaded('purchaseInvoices')
            ? $this->purchaseInvoices->contains(fn (PurchaseInvoice $invoice) => $invoice->status !== PurchaseInvoiceStatus::CANCELLED)
            : $this->activePurchaseInvoices()->exists();

        return ! $hasInvoices;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    }

    public function addAttachments($files)
    {
        if (! is_array($files)) {
            $files = [$files];
        }

        try {
            foreach ($files as $file) {
                if (! $file instanceof \Illuminate\Http\UploadedFile) {
                    continue;
                }

                $originalName = $file->getClientOriginalName();
                $extension = $file->getClientOriginalExtension();
                $fileName = time().'_'.uniqid().'_'.\Illuminate\Support\Str::slug(pathinfo($originalName, PATHINFO_FILENAME)).'.'.$extension;

                $this->addMedia($file)
                    ->usingFileName($fileName)
                    ->toMediaCollection('attachments', 'accounting');
            }

            return true;
        } catch (\Exception $e) {
            \Log::error('Error uploading purchase order attachment: '.$e->getMessage());

            return null;
        }
    }
}
