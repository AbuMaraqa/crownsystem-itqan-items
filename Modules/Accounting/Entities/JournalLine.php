<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Entities\Branch;
use Modules\Core\Entities\Currency;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class JournalLine extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'branch_id',
        'currency_id',
        'exchange_rate',
        'debit',
        'credit',
        'debit_base',
        'credit_base',
        'description',
        'created_by',
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:8',
        'debit' => 'decimal:4',
        'credit' => 'decimal:4',
        'debit_base' => 'decimal:4',
        'credit_base' => 'decimal:4',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'journal_lines');
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

    protected static function booted(): void
    {
        static::saving(function (JournalLine $line) {
            $attributes = $line->getAttributes();
            $exchangeRate = self::decimalValue($attributes['exchange_rate'] ?? 1, 1, 8);
            $debit = self::decimalValue($attributes['debit'] ?? 0);
            $credit = self::decimalValue($attributes['credit'] ?? 0);
            $debitBase = self::decimalValue($attributes['debit_base'] ?? null, round($debit * $exchangeRate, 4));
            $creditBase = self::decimalValue($attributes['credit_base'] ?? null, round($credit * $exchangeRate, 4));

            $line->forceFill([
                'exchange_rate' => $exchangeRate,
                'debit' => $debit,
                'credit' => $credit,
                'debit_base' => $debitBase,
                'credit_base' => $creditBase,
            ]);
        });
    }

    private static function decimalValue(mixed $value, float $default = 0, int $scale = 4): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        return is_numeric($value) ? round((float) $value, $scale) : $default;
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
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
