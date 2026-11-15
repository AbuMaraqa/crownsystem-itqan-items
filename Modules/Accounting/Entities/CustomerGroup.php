<?php

namespace Modules\Accounting\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * تصنيف زبائن يجمع اكثر من زبون على نسبة ربح واحدة.
 */
class CustomerGroup extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'profit_margin',
        'use_last_price',
        'price_list_id',
        'notes',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'profit_margin' => 'decimal:4',
        'use_last_price' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'customer_groups');
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(User::class, 'customer_group_id');
    }

    /**
     * التصنيفات المفعّلة فقط، لاستخدامها في قوائم الاختيار.
     */
    public static function options(): array
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'profit_margin'])
            ->mapWithKeys(fn (self $group): array => [
                $group->id => $group->name.' ('.self::formatMargin($group->profit_margin).'%)',
            ])
            ->toArray();
    }

    public static function formatMargin(mixed $margin): string
    {
        return rtrim(rtrim(number_format((float) $margin, 4, '.', ''), '0'), '.');
    }
}
