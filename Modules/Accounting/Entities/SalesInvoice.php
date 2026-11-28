<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\SalesInvoiceStatus;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Concerns\HasSearchIndex;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SalesInvoice extends Model
{
    use HasFactory;
    use HasSearchIndex;
    use LogsActivity;
    use SoftDeletes;

    /**
     * البحث في فواتير البيع: بالرقم المرجعي والملاحظات.
     *
     * @return list<string>
     */
    public static function searchColumns(): array
    {
        return ['reference_no', 'notes'];
    }

    protected $fillable = [
        'sales_order_id',
        'client_id',
        'branch_id',
        'warehouse_id',
        'reference_no',
        'status',
        'invoice_date',
        'due_date',
        'currency_id',
        'tax_id',
        'exchange_rate',
        'sub_total',
        'items_discount',
        'invoice_discount',
        'tax_rate',
        'tax_amount',
        'total_amount',
        'journal_entry_id',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'paid_amount',
        'outstanding_amount',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'status' => SalesInvoiceStatus::class,
        'cancelled_at' => 'datetime',
        'paid_amount' => 'decimal:4',
        'outstanding_amount' => 'decimal:4',
        'exchange_rate' => 'decimal:8',
        'sub_total' => 'decimal:4',
        'items_discount' => 'decimal:4',
        'invoice_discount' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'sales_invoices');
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

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class, 'sales_invoice_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isPosted(): bool
    {
        return $this->status === SalesInvoiceStatus::POSTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === SalesInvoiceStatus::CANCELLED;
    }

    public function canBeEdited(): bool
    {
        if ($this->status !== SalesInvoiceStatus::DRAFT) {
            return false;
        }

        $journalEntry = $this->relationLoaded('journalEntry') ? $this->journalEntry : $this->journalEntry()->first();

        return ! $journalEntry || $journalEntry->status === JournalEntryStatus::DRAFT;
    }

    public function canBePosted(): bool
    {
        $journalEntry = $this->relationLoaded('journalEntry') ? $this->journalEntry : $this->journalEntry()->first();

        return $this->status === SalesInvoiceStatus::DRAFT
            && (! $journalEntry || $journalEntry->status === JournalEntryStatus::DRAFT);
    }
}
