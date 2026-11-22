<?php

namespace Modules\Items\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Entities\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * لون (دهان) الصنف: قائمة واحدة لكل الماركات (أبيض، ميش، بدون دهان، رال 7016...).
 * «بلا لون» صف نظامي (is_system) للصنف الموزون الذي لا ألوان له.
 */
class Finish extends Model
{
    use LogsActivity;
    use SoftDeletes;

    public const COLOUR_ATTRIBUTE = 'colour';

    protected $fillable = [
        'name',
        'code',
        'is_system',
        'is_active',
        'sort_order',
        'attribute_value_id',
        'created_by',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('items.table_prefix').'finishes');
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
     * «بلا لون»: تُنشئه مهاجرة الجدول، فوجوده مضمون بعد النشر.
     */
    public static function system(): self
    {
        return static::query()->where('is_system', true)->firstOrFail();
    }

    /**
     * الألوان المفعّلة لقوائم الاختيار.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * سمة «اللون» النظامية التي يقرأ منها المتجر الإلكتروني ألوان المتغيرات (مهاجرة 2026_11_22_000001).
     */
    public static function colourAttributeId(): int
    {
        return (int) Attribute::query()->where('slug', self::COLOUR_ATTRIBUTE)->value('id');
    }

    /**
     * قيمة هذا اللون في سمة «اللون»: صورة منه للمتجر، واللون هو المصدر.
     */
    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class, 'attribute_value_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function categoryFinishes(): HasMany
    {
        return $this->hasMany(CategoryFinish::class, 'finish_id');
    }
}
