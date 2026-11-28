<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Enums\ChequeDirection;
use Modules\Accounting\Enums\ChequeStatus;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Concerns\HasSearchIndex;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Cheque extends Model
{
    use HasFactory;
    use HasSearchIndex;
    use LogsActivity;
    use SoftDeletes;

    /**
     * البحث في الشيكات: برقم الشيك واسم البنك.
     *
     * @return list<string>
     */
    public static function searchColumns(): array
    {
        return ['cheque_number', 'bank_name'];
    }

    protected $fillable = [
        'chequeable_type',
        'chequeable_id',
        'direction',
        'cheque_number',
        'bank_name',
        'cheque_date',
        'client_id',
        'branch_id',
        'currency_id',
        'exchange_rate',
        'amount',
        'account_id',
        'status',
        'clearing_account_id',
        'journal_entry_id',
        'cleared_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'cheque_date' => 'date',
        'cleared_at' => 'datetime',
        'direction' => ChequeDirection::class,
        'status' => ChequeStatus::class,
        'exchange_rate' => 'decimal:8',
        'amount' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'cheques');
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

    public function chequeable(): MorphTo
    {
        return $this->morphTo();
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function clearingAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'clearing_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [ChequeStatus::CLEARED, ChequeStatus::BOUNCED, ChequeStatus::CANCELLED], true);
    }
}
