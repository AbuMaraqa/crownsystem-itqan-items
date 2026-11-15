<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\ReceiptVoucherStatus;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ReceiptVoucher extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'branch_id',
        'account_id',
        'reference_no',
        'status',
        'voucher_date',
        'currency_id',
        'exchange_rate',
        'payment_method',
        'amount',
        'cheque_number',
        'cheque_bank_name',
        'cheque_date',
        'journal_entry_id',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'allocated_amount',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'cheque_date' => 'date',
        'status' => ReceiptVoucherStatus::class,
        'cancelled_at' => 'datetime',
        'allocated_amount' => 'decimal:4',
        'payment_method' => \Modules\Accounting\Enums\AccountingPaymentMethod::class,
        'exchange_rate' => 'decimal:8',
        'amount' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'receipt_vouchers');
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function cheque(): MorphOne
    {
        return $this->morphOne(Cheque::class, 'chequeable');
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
        return $this->status === ReceiptVoucherStatus::POSTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === ReceiptVoucherStatus::CANCELLED;
    }

    public function canBeEdited(): bool
    {
        if ($this->status !== ReceiptVoucherStatus::DRAFT) {
            return false;
        }

        $journalEntry = $this->relationLoaded('journalEntry') ? $this->journalEntry : $this->journalEntry()->first();

        return ! $journalEntry || $journalEntry->status === JournalEntryStatus::DRAFT;
    }

    public function canBePosted(): bool
    {
        $journalEntry = $this->relationLoaded('journalEntry') ? $this->journalEntry : $this->journalEntry()->first();

        return $this->status === ReceiptVoucherStatus::DRAFT
            && (! $journalEntry || $journalEntry->status === JournalEntryStatus::DRAFT);
    }
}
