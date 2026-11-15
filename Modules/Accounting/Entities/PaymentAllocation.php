<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * تخصيص مبلغ من سند (قبض/صرف) او مرتجع على فاتورة (بيع/شراء) بعملة الفاتورة.
 * مجموع تخصيصات الفاتورة = المسدَّد منها، والباقي = المتبقي (اعمار الديون).
 */
class PaymentAllocation extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'source_type',
        'source_id',
        'invoice_type',
        'invoice_id',
        'client_id',
        'currency_id',
        'allocation_date',
        'amount',
        'invoice_exchange_rate',
        'source_exchange_rate',
        'exchange_difference_base',
        'journal_entry_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'allocation_date' => 'date',
        'amount' => 'decimal:4',
        'invoice_exchange_rate' => 'decimal:8',
        'source_exchange_rate' => 'decimal:8',
        'exchange_difference_base' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'payment_allocations');
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

    public function source(): MorphTo
    {
        return $this->morphTo('source', 'source_type', 'source_id');
    }

    public function invoice(): MorphTo
    {
        return $this->morphTo('invoice', 'invoice_type', 'invoice_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
