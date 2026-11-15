<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class JournalEntry extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'entry_number',
        'fiscal_year_id',
        'accounting_period_id',
        'entry_date',
        'status',
        'reference_no',
        'source_module',
        'source_type',
        'sourceable_id',
        'sourceable_type',
        'reversal_of_entry_id',
        'description',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
        'status' => JournalEntryStatus::class,
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix') . 'journal_entries');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->getFillable())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "This model has been {$eventName} and value ")
            ->useLogName(class_basename($this));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_entry_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'reversal_of_entry_id');
    }

    public function isReversal(): bool
    {
        return filled($this->reversal_of_entry_id);
    }

    public function hasReversal(): bool
    {
        if (array_key_exists('reversals_count', $this->attributes)) {
            return (int) $this->attributes['reversals_count'] > 0;
        }

        return $this->reversals()->exists();
    }

    public function canBeVoided(): bool
    {
        return $this->status === JournalEntryStatus::POSTED
            && $this->isManual()
            && ! $this->isReversal()
            && ! $this->hasReversal();
    }

    /**
     * القيد اليدوي هو الذي لا يتبع مستنداً. قيد الفاتورة او السند او المخزون يُلغى من
     * مستنده فقط (فيُعكس المخزون وتتغير حالة المستند معه)، لا من شاشة القيود.
     */
    public function isManual(): bool
    {
        return blank($this->sourceable_type) && blank($this->source_type);
    }

    public function isBalanced(): bool
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return abs((float) $lines->sum('debit_base') - (float) $lines->sum('credit_base')) <= 0.0001;
    }

    public function totalDebitBase(): string
    {
        return number_format((float) $this->lines()->sum('debit_base'), 4, '.', '');
    }

    public function totalCreditBase(): string
    {
        return number_format((float) $this->lines()->sum('credit_base'), 4, '.', '');
    }
}
