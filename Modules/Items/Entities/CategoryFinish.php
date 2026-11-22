<?php

namespace Modules\Items\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * مجموعة السعر (التصنيف × اللون)، مثل «الوبين × بدون دهان»: عليها سعر الكيلو لكل
 * قائمة سعر، وعليها رصيد الكيلوغرامات وتكلفته، بدل الصنف الرئيسي الوهمي.
 * لا تظهر للمستخدم ككيان؛ يراها جدول «الألوان والأسعار» في صفحة التصنيف.
 */
class CategoryFinish extends Model
{
    use LogsActivity;

    protected $fillable = [
        'category_id',
        'finish_id',
        'legacy_code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('items.table_prefix').'category_finishes');
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function finish(): BelongsTo
    {
        return $this->belongsTo(Finish::class, 'finish_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(CategoryFinishPrice::class, 'category_finish_id');
    }

    /**
     * اسم مجموعة السعر في المستندات: «التصنيف — اللون»، مثل «الوبين — أبيض». يحتاج category و finish محمّلين.
     */
    public function label(): string
    {
        return trim($this->category?->name.' — '.$this->finish?->name, ' —');
    }

    /**
     * اسم اللون كما يظهر في متغيّر صنف من التصنيفات المعطاة: «أبيض»، او «نابكو أبيض» اذا كانت
     * مجموعة السعر من تصنيف آخر (كما في النقل R-3). يحتاج category و finish محمّلين.
     *
     * @param  array<int, int|string>  $categoryIds  تصنيفات الصنف الاب
     */
    public function labelFor(array $categoryIds): string
    {
        $finish = (string) $this->finish?->name;

        if (in_array((int) $this->category_id, array_map('intval', $categoryIds), true)) {
            return $finish;
        }

        return trim($this->category?->name.' '.$finish);
    }
}
