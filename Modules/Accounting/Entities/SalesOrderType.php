<?php

namespace Modules\Accounting\Entities;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Core\Entities\Concerns\HasSortOrder;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * نوع طلبية البيع («دليل أنواع الطلبيات»): تحضير من المخزن، دهان، توصية من مورد... ولكل نوع مهلة
 * قصوى بالأيام لتحضير الطلبية وجاهزيتها للفوترة، تُحسب من تأكيد الطلبية (readyBy).
 */
class SalesOrderType extends Model
{
    use HasSortOrder;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'lead_days',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'lead_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('accounting.table_prefix').'sales_order_types');
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

    /**
     * موعد جاهزية الطلبية للفوترة: يوم تأكيدها + مهلة النوع.
     */
    public function readyBy(CarbonInterface $confirmedAt): Carbon
    {
        return Carbon::parse($confirmedAt)->startOfDay()->addDays($this->lead_days);
    }

    /**
     * الأنواع المفعّلة بترتيبها لقائمة الاختيار، ومع كل نوع مهلته.
     *
     * @return list<array{value: int, label: string, lead_days: int}>
     */
    public static function options(): array
    {
        return static::query()
            ->where('is_active', true)
            ->ordered()
            ->get(['id', 'name', 'lead_days'])
            ->map(fn (self $type): array => ['value' => (int) $type->id, 'label' => $type->name, 'lead_days' => $type->lead_days])
            ->all();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'order_type_id');
    }
}
